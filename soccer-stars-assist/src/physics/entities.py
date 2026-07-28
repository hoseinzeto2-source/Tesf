from __future__ import annotations

from dataclasses import dataclass, field
import math


@dataclass
class Vec2:
    x: float
    y: float

    def __add__(self, other: Vec2) -> Vec2:
        return Vec2(self.x + other.x, self.y + other.y)

    def __sub__(self, other: Vec2) -> Vec2:
        return Vec2(self.x - other.x, self.y - other.y)

    def __mul__(self, scalar: float) -> Vec2:
        return Vec2(self.x * scalar, self.y * scalar)

    def length(self) -> float:
        return math.hypot(self.x, self.y)

    def normalized(self) -> Vec2:
        length = self.length()
        if length < 1e-8:
            return Vec2(0.0, 0.0)
        return Vec2(self.x / length, self.y / length)

    def dot(self, other: Vec2) -> float:
        return self.x * other.x + self.y * other.y

    def reflect(self, normal: Vec2) -> Vec2:
        n = normal.normalized()
        return self - n * (2.0 * self.dot(n))


@dataclass
class CircleBody:
    x: float
    y: float
    radius: float
    vx: float = 0.0
    vy: float = 0.0
    mass: float = 1.0
    restitution: float = 0.92
    friction: float = 0.985
    kind: str = "puck"
    team: str | None = None
    id: int = 0

    @property
    def pos(self) -> Vec2:
        return Vec2(self.x, self.y)

    @pos.setter
    def pos(self, value: Vec2) -> None:
        self.x = value.x
        self.y = value.y

    @property
    def vel(self) -> Vec2:
        return Vec2(self.vx, self.vy)

    @vel.setter
    def vel(self, value: Vec2) -> None:
        self.vx = value.x
        self.vy = value.y

    def speed(self) -> float:
        return self.vel.length()


@dataclass
class FieldBounds:
    left: float
    top: float
    right: float
    bottom: float

    def clamp_point(self, x: float, y: float, radius: float) -> tuple[float, float]:
        return (
            min(max(x, self.left + radius), self.right - radius),
            min(max(y, self.top + radius), self.bottom - radius),
        )
