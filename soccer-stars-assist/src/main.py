#!/usr/bin/env python3
from __future__ import annotations

import json
import math
import sys
from pathlib import Path

import cv2
import mss
import numpy as np

ROOT = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT))

from overlay.window import OverlayData, run_overlay_loop
from physics.engine import PhysicsEngine, ShotInput
from physics.entities import FieldBounds
from vision.detector import GameDetector


def load_config() -> dict:
    config_path = ROOT.parent / "config" / "default.json"
    with config_path.open("r", encoding="utf-8") as f:
        return json.load(f)


def build_ruler_points(
    start: tuple[float, float],
    direction: tuple[float, float],
    power: float,
    *,
    extension_px: float,
    tick_step_px: float,
) -> list[tuple[float, float]]:
    dx, dy = direction
    length = math.hypot(dx, dy)
    if length < 1e-6:
        return [start]
    ux, uy = dx / length, dy / length
    total = min(extension_px, 80 + power * 1.6)
    points: list[tuple[float, float]] = []
    steps = max(2, int(total // tick_step_px))
    for i in range(steps + 1):
        dist = (total / steps) * i
        points.append((start[0] + ux * dist, start[1] + uy * dist))
    return points


def goal_rect_for_bounds(bounds: FieldBounds, config: dict, top_side: bool) -> tuple[float, float, float, float]:
    goal_cfg = config["goal"]
    margin = goal_cfg["margin_px"]
    top = bounds.top + (bounds.bottom - bounds.top) * goal_cfg["top_ratio"]
    bottom = bounds.top + (bounds.bottom - bounds.top) * goal_cfg["bottom_ratio"]
    if top_side:
        return (bounds.left + margin, top, bounds.left + margin * 3, bottom)
    return (bounds.right - margin * 3, top, bounds.right - margin, bottom)


class AssistApp:
    def __init__(self, config: dict) -> None:
        self.config = config
        self.detector = GameDetector(config["physics"])
        self.physics = PhysicsEngine(FieldBounds(0, 0, 1, 1))
        self.capture_region = config["capture"].get("region")
        self.monitor_index = config["capture"].get("monitor", 1)
        self.last_status = "در حال آماده‌سازی..."

    def capture_frame(self, sct: mss.mss) -> tuple[np.ndarray, tuple[int, int, int, int]]:
        if self.capture_region:
            region = self.capture_region
            monitor = {
                "left": region["left"],
                "top": region["top"],
                "width": region["width"],
                "height": region["height"],
            }
            geometry = (region["left"], region["top"], region["width"], region["height"])
        else:
            monitor = sct.monitors[self.monitor_index]
            geometry = (monitor["left"], monitor["top"], monitor["width"], monitor["height"])

        shot = sct.grab(monitor)
        frame = np.array(shot)[:, :, :3]
        frame_bgr = cv2.cvtColor(frame, cv2.COLOR_BGRA2BGR)
        return frame_bgr, geometry

    def process(self, sct: mss.mss) -> tuple[OverlayData, tuple[int, int, int, int]]:
        frame, geometry = self.capture_frame(sct)
        detection = self.detector.detect(frame)

        if detection.bounds is None:
            return OverlayData(status_text="زمین بازی پیدا نشد"), geometry

        self.physics.bounds = detection.bounds
        shooter = self.detector.nearest_puck_to_aim(detection)

        if not detection.aim.active or shooter is None or detection.ball is None:
            self.last_status = "مهره را بگیرید و بکشید"
            return OverlayData(status_text=self.last_status), geometry

        shot = ShotInput(
            puck=shooter,
            direction=detection.aim.direction,
            power=detection.aim.power,
            max_power=self.config["physics"]["max_shot_power"],
            max_speed=self.config["physics"]["max_shot_speed"],
        )
        bodies = [shooter, detection.ball, *[p for p in detection.pucks if p.id != shooter.id]]
        goal_rect = None
        if self.config["goal"]["enabled"]:
            # Assume player shoots upward when ball is below puck center.
            top_side = detection.ball.y < shooter.y
            goal_rect = goal_rect_for_bounds(detection.bounds, self.config, top_side=top_side)

        result = self.physics.simulate_shot(bodies, shot, goal_rect=goal_rect)

        aim_start = (shooter.x, shooter.y)
        ruler_points = build_ruler_points(
            aim_start,
            (detection.aim.direction.x, detection.aim.direction.y),
            detection.aim.power,
            extension_px=self.config["overlay"]["ruler_extension_px"],
            tick_step_px=self.config["overlay"]["ruler_tick_step_px"],
        )

        status = "پیش‌بینی مسیر توپ"
        if result.goal_scored:
            status = "احتمال گل!"

        overlay = OverlayData(
            aim_active=True,
            aim_start=aim_start,
            aim_end=detection.aim.end,
            ruler_points=ruler_points,
            puck_path=result.puck_path,
            ball_path=result.ball_path,
            goal_scored=result.goal_scored,
            status_text=status,
        )
        self.last_status = status
        return overlay, geometry


def main() -> None:
    config = load_config()
    app = AssistApp(config)

    with mss.mss() as sct:
        _, geometry = app.capture_frame(sct)

        def refresh() -> OverlayData:
            overlay, _ = app.process(sct)
            return overlay

        run_overlay_loop(geometry, refresh, interval_ms=int(1000 / config["capture"]["fps"]))


if __name__ == "__main__":
    main()
