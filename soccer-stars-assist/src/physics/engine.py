from __future__ import annotations

import math
from dataclasses import dataclass

from .entities import CircleBody, FieldBounds, Vec2


@dataclass
class ShotInput:
    puck: CircleBody
    direction: Vec2
    power: float
    max_power: float = 140.0
    max_speed: float = 28.0


@dataclass
class SimulationResult:
    puck_path: list[tuple[float, float]]
    ball_path: list[tuple[float, float]]
    goal_scored: bool
    steps: int


class PhysicsEngine:
  """Simplified Soccer Stars physics: circle collisions + wall reflection."""

  def __init__(
      self,
      bounds: FieldBounds,
      *,
      stop_speed: float = 0.08,
      max_steps: int = 2500,
      dt: float = 1.0,
  ) -> None:
      self.bounds = bounds
      self.stop_speed = stop_speed
      self.max_steps = max_steps
      self.dt = dt

  def power_to_speed(self, power: float, max_power: float, max_speed: float) -> float:
      ratio = min(max(power / max(max_power, 1e-6), 0.0), 1.0)
      return ratio * max_speed

  def simulate_shot(
      self,
      bodies: list[CircleBody],
      shot: ShotInput,
      goal_rect: tuple[float, float, float, float] | None = None,
  ) -> SimulationResult:
      sim_bodies = [
          CircleBody(
              x=b.x,
              y=b.y,
              radius=b.radius,
              vx=b.vx,
              vy=b.vy,
              mass=b.mass,
              restitution=b.restitution,
              friction=b.friction,
              kind=b.kind,
              team=b.team,
              id=b.id,
          )
          for b in bodies
      ]

      shooter = next(b for b in sim_bodies if b.id == shot.puck.id)
      direction = shot.direction.normalized()
      speed = self.power_to_speed(shot.power, shot.max_power, shot.max_speed)
      shooter.vx = direction.x * speed
      shooter.vy = direction.y * speed

      puck_path: list[tuple[float, float]] = [(shooter.x, shooter.y)]
      ball = next((b for b in sim_bodies if b.kind == "ball"), None)
      ball_path: list[tuple[float, float]] = []
      if ball is not None:
          ball_path.append((ball.x, ball.y))

      goal_scored = False
      steps = 0
      for _ in range(self.max_steps):
          moving = [b for b in sim_bodies if b.speed() > self.stop_speed]
          if not moving:
              break

          steps += 1
          for body in moving:
              body.x += body.vx * self.dt
              body.y += body.vy * self.dt
              self._resolve_wall_collision(body)

          for i in range(len(sim_bodies)):
              for j in range(i + 1, len(sim_bodies)):
                  self._resolve_circle_collision(sim_bodies[i], sim_bodies[j])

          for body in moving:
              body.vx *= body.friction
              body.vy *= body.friction
              if body.speed() <= self.stop_speed:
                  body.vx = 0.0
                  body.vy = 0.0

          if shooter.speed() > self.stop_speed:
              puck_path.append((shooter.x, shooter.y))
          if ball is not None and ball.speed() > self.stop_speed:
              ball_path.append((ball.x, ball.y))
              if goal_rect and self._in_goal(ball, goal_rect):
                  goal_scored = True
                  break

      return SimulationResult(
          puck_path=puck_path,
          ball_path=ball_path,
          goal_scored=goal_scored,
          steps=steps,
      )

  def _in_goal(self, ball: CircleBody, goal_rect: tuple[float, float, float, float]) -> bool:
      left, top, right, bottom = goal_rect
      return left <= ball.x <= right and top <= ball.y <= bottom

  def _resolve_wall_collision(self, body: CircleBody) -> None:
      bounds = self.bounds
      radius = body.radius

      if body.x - radius < bounds.left:
          body.x = bounds.left + radius
          body.vx = abs(body.vx) * body.restitution
      elif body.x + radius > bounds.right:
          body.x = bounds.right - radius
          body.vx = -abs(body.vx) * body.restitution

      if body.y - radius < bounds.top:
          body.y = bounds.top + radius
          body.vy = abs(body.vy) * body.restitution
      elif body.y + radius > bounds.bottom:
          body.y = bounds.bottom - radius
          body.vy = -abs(body.vy) * body.restitution

  def _resolve_circle_collision(self, a: CircleBody, b: CircleBody) -> None:
      dx = b.x - a.x
      dy = b.y - a.y
      distance = math.hypot(dx, dy)
      min_distance = a.radius + b.radius
      if distance >= min_distance or distance < 1e-8:
          return

      nx = dx / distance
      ny = dy / distance
      overlap = min_distance - distance

      total_mass = a.mass + b.mass
      a.x -= nx * overlap * (b.mass / total_mass)
      a.y -= ny * overlap * (b.mass / total_mass)
      b.x += nx * overlap * (a.mass / total_mass)
      b.y += ny * overlap * (a.mass / total_mass)

      rvx = b.vx - a.vx
      rvy = b.vy - a.vy
      vel_along_normal = rvx * nx + rvy * ny
      if vel_along_normal > 0:
          return

      restitution = min(a.restitution, b.restitution)
      impulse = -(1.0 + restitution) * vel_along_normal / (1.0 / a.mass + 1.0 / b.mass)
      impulse_x = impulse * nx
      impulse_y = impulse * ny

      a.vx -= impulse_x / a.mass
      a.vy -= impulse_y / a.mass
      b.vx += impulse_x / b.mass
      b.vy += impulse_y / b.mass
