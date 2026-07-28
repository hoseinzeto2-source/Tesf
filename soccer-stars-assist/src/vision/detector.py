from __future__ import annotations

from dataclasses import dataclass
import math

import cv2
import numpy as np

from physics.entities import CircleBody, FieldBounds, Vec2


@dataclass
class AimState:
    active: bool
    start: tuple[float, float] | None
    end: tuple[float, float] | None
    direction: Vec2
    power: float


@dataclass
class FrameDetection:
    bounds: FieldBounds | None
    ball: CircleBody | None
    pucks: list[CircleBody]
    aim: AimState
    field_mask: np.ndarray | None = None


class GameDetector:
    """Detect field, ball, pucks, and in-game aim line from a screen frame."""

    def __init__(self, config: dict | None = None) -> None:
        config = config or {}
        self.ball_radius = config.get("ball_radius", 12)
        self.puck_radius = config.get("puck_radius", 22)
        self.puck_mass = config.get("puck_mass", 2.0)
        self.ball_mass = config.get("ball_mass", 1.0)
        self.max_shot_power = config.get("max_shot_power", 140.0)
        self.aim_line_min_length = config.get("aim_line_min_length", 18)

    def detect(self, frame_bgr: np.ndarray) -> FrameDetection:
        h, w = frame_bgr.shape[:2]
        field_mask = self._detect_field_mask(frame_bgr)
        bounds = self._bounds_from_mask(field_mask, w, h)

        ball = self._detect_ball(frame_bgr, field_mask)
        pucks = self._detect_pucks(frame_bgr, field_mask, ball)
        aim = self._detect_aim_line(frame_bgr, pucks)

        return FrameDetection(
            bounds=bounds,
            ball=ball,
            pucks=pucks,
            aim=aim,
            field_mask=field_mask,
        )

    def _detect_field_mask(self, frame_bgr: np.ndarray) -> np.ndarray:
        hsv = cv2.cvtColor(frame_bgr, cv2.COLOR_BGR2HSV)
        # Green pitch variants in Soccer Stars.
        lower = np.array([35, 40, 40], dtype=np.uint8)
        upper = np.array([90, 255, 255], dtype=np.uint8)
        mask = cv2.inRange(hsv, lower, upper)
        kernel = np.ones((7, 7), np.uint8)
        mask = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, kernel, iterations=2)
        mask = cv2.morphologyEx(mask, cv2.MORPH_OPEN, kernel, iterations=1)
        return mask

    def _bounds_from_mask(
        self, mask: np.ndarray, width: int, height: int
    ) -> FieldBounds | None:
        contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        if not contours:
            return FieldBounds(20, 80, width - 20, height - 80)

        largest = max(contours, key=cv2.contourArea)
        x, y, w, h = cv2.boundingRect(largest)
        if w * h < width * height * 0.15:
            return FieldBounds(20, 80, width - 20, height - 80)

        return FieldBounds(
            left=float(x + 8),
            top=float(y + 8),
            right=float(x + w - 8),
            bottom=float(y + h - 8),
        )

    def _detect_ball(
        self, frame_bgr: np.ndarray, field_mask: np.ndarray
    ) -> CircleBody | None:
        hsv = cv2.cvtColor(frame_bgr, cv2.COLOR_BGR2HSV)
        # White / light ball.
        lower = np.array([0, 0, 190], dtype=np.uint8)
        upper = np.array([180, 70, 255], dtype=np.uint8)
        mask = cv2.inRange(hsv, lower, upper)
        mask = cv2.bitwise_and(mask, field_mask)

        circles = cv2.HoughCircles(
            cv2.GaussianBlur(mask, (9, 9), 2),
            cv2.HOUGH_GRADIENT,
            dp=1.2,
            minDist=30,
            param1=60,
            param2=12,
            minRadius=6,
            maxRadius=20,
        )
        if circles is None:
            return None

        x, y, r = circles[0][0]
        return CircleBody(
            x=float(x),
            y=float(y),
            radius=float(r),
            mass=self.ball_mass,
            kind="ball",
            id=-1,
        )

    def _detect_pucks(
        self,
        frame_bgr: np.ndarray,
        field_mask: np.ndarray,
        ball: CircleBody | None,
    ) -> list[CircleBody]:
        gray = cv2.cvtColor(frame_bgr, cv2.COLOR_BGR2GRAY)
        masked = cv2.bitwise_and(gray, gray, mask=field_mask)
        blurred = cv2.GaussianBlur(masked, (9, 9), 2)

        circles = cv2.HoughCircles(
            blurred,
            cv2.HOUGH_GRADIENT,
            dp=1.1,
            minDist=28,
            param1=80,
            param2=24,
            minRadius=14,
            maxRadius=34,
        )
        if circles is None:
            return []

        pucks: list[CircleBody] = []
        for idx, (x, y, r) in enumerate(circles[0]):
            if ball is not None and math.hypot(x - ball.x, y - ball.y) < (r + ball.radius) * 0.8:
                continue
            pucks.append(
                CircleBody(
                    x=float(x),
                    y=float(y),
                    radius=float(r),
                    mass=self.puck_mass,
                    kind="puck",
                    id=idx,
                )
            )
        return pucks

    def _detect_aim_line(self, frame_bgr: np.ndarray, pucks: list[CircleBody]) -> AimState:
        hsv = cv2.cvtColor(frame_bgr, cv2.COLOR_BGR2HSV)
        # Yellow aim guide line in Soccer Stars.
        lower = np.array([18, 90, 120], dtype=np.uint8)
        upper = np.array([38, 255, 255], dtype=np.uint8)
        mask = cv2.inRange(hsv, lower, upper)
        mask = cv2.dilate(mask, np.ones((3, 3), np.uint8), iterations=1)

        lines = cv2.HoughLinesP(
            mask,
            rho=1,
            theta=np.pi / 180,
            threshold=35,
            minLineLength=self.aim_line_min_length,
            maxLineGap=8,
        )
        if lines is None or not pucks:
            return AimState(False, None, None, Vec2(0, 0), 0.0)

        best = None
        best_score = -1.0
        for line in lines:
            x1, y1, x2, y2 = line[0]
            length = math.hypot(x2 - x1, y2 - y1)
            if length < self.aim_line_min_length:
                continue

            for puck in pucks:
                dist = self._point_segment_distance(puck.x, puck.y, x1, y1, x2, y2)
                if dist > puck.radius * 1.4:
                    continue
                score = length - dist
                if score > best_score:
                    best_score = score
                    best = (x1, y1, x2, y2, puck, length)

        if best is None:
            return AimState(False, None, None, Vec2(0, 0), 0.0)

        x1, y1, x2, y2, puck, length = best
        dx = x2 - x1
        dy = y2 - y1
        direction = Vec2(dx, dy).normalized()
        # Shot direction is opposite to aim line drag (slingshot).
        shot_dir = Vec2(-direction.x, -direction.y)
        power = min(length, self.max_shot_power)
        return AimState(True, (x1, y1), (x2, y2), shot_dir, power)

    @staticmethod
    def _point_segment_distance(
        px: float, py: float, x1: float, y1: float, x2: float, y2: float
    ) -> float:
        vx = x2 - x1
        vy = y2 - y1
        if vx == 0 and vy == 0:
            return math.hypot(px - x1, py - y1)
        t = max(0.0, min(1.0, ((px - x1) * vx + (py - y1) * vy) / (vx * vx + vy * vy)))
        proj_x = x1 + t * vx
        proj_y = y1 + t * vy
        return math.hypot(px - proj_x, py - proj_y)

    def nearest_puck_to_aim(self, detection: FrameDetection) -> CircleBody | None:
        if not detection.aim.active or not detection.pucks:
            return None
        if detection.aim.start is None:
            return None
        sx, sy = detection.aim.start
        return min(detection.pucks, key=lambda p: math.hypot(p.x - sx, p.y - sy))
