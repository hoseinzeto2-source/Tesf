from __future__ import annotations

from dataclasses import dataclass

from PyQt6.QtCore import Qt, QTimer
from PyQt6.QtGui import QColor, QFont, QPainter, QPen
from PyQt6.QtWidgets import QApplication, QWidget

from physics.engine import SimulationResult


@dataclass
class OverlayData:
    aim_active: bool = False
    aim_start: tuple[float, float] | None = None
    aim_end: tuple[float, float] | None = None
    ruler_points: list[tuple[float, float]] | None = None
    puck_path: list[tuple[float, float]] | None = None
    ball_path: list[tuple[float, float]] | None = None
    goal_scored: bool = False
    status_text: str = ""


class AimOverlay(QWidget):
    """Transparent always-on-top overlay for extended aim ruler and ball path."""

    def __init__(self, geometry: tuple[int, int, int, int]) -> None:
        super().__init__()
        self.data = OverlayData()
        x, y, w, h = geometry
        self.setGeometry(x, y, w, h)
        self.setWindowFlags(
            Qt.WindowType.FramelessWindowHint
            | Qt.WindowType.WindowStaysOnTopHint
            | Qt.WindowType.Tool
        )
        self.setAttribute(Qt.WidgetAttribute.WA_TranslucentBackground)
        self.setAttribute(Qt.WidgetAttribute.WA_TransparentForMouseEvents)

    def update_data(self, data: OverlayData) -> None:
        self.data = data
        self.update()

    def paintEvent(self, _event) -> None:
        painter = QPainter(self)
        painter.setRenderHint(QPainter.RenderHint.Antialiasing)

        if self.data.aim_active and self.data.ruler_points:
            self._draw_ruler_line(painter, self.data.ruler_points)

        if self.data.puck_path:
            self._draw_path(painter, self.data.puck_path, QColor(255, 220, 0, 210), 3)

        if self.data.ball_path:
            color = QColor(0, 255, 120, 230) if self.data.goal_scored else QColor(0, 200, 255, 220)
            self._draw_path(painter, self.data.ball_path, color, 4)

        if self.data.status_text:
            painter.setFont(QFont("Arial", 11, QFont.Weight.Bold))
            painter.setPen(QColor(255, 255, 255, 230))
            painter.drawText(12, 24, self.data.status_text)

    def _draw_ruler_line(self, painter: QPainter, points: list[tuple[float, float]]) -> None:
        if len(points) < 2:
            return

        pen = QPen(QColor(255, 255, 255, 240))
        pen.setWidth(2)
        pen.setStyle(Qt.PenStyle.SolidLine)
        painter.setPen(pen)
        for i in range(len(points) - 1):
            x1, y1 = points[i]
            x2, y2 = points[i + 1]
            painter.drawLine(int(x1), int(y1), int(x2), int(y2))

        tick_pen = QPen(QColor(255, 255, 255, 200))
        tick_pen.setWidth(1)
        painter.setPen(tick_pen)
        step = 18
        for i in range(0, len(points) - 1, 2):
            x, y = points[i]
            painter.drawLine(int(x - 5), int(y), int(x + 5), int(y))
            painter.drawLine(int(x), int(y - 5), int(x), int(y + 5))

        arrow_x, arrow_y = points[-1]
        prev_x, prev_y = points[-2]
        painter.setPen(QPen(QColor(255, 80, 80, 240), 3))
        painter.drawLine(int(prev_x), int(prev_y), int(arrow_x), int(arrow_y))

    def _draw_path(
        self,
        painter: QPainter,
        path: list[tuple[float, float]],
        color: QColor,
        width: int,
    ) -> None:
        if len(path) < 2:
            return
        pen = QPen(color)
        pen.setWidth(width)
        pen.setStyle(Qt.PenStyle.DashLine)
        painter.setPen(pen)
        for i in range(len(path) - 1):
            x1, y1 = path[i]
            x2, y2 = path[i + 1]
            painter.drawLine(int(x1), int(y1), int(x2), int(y2))


def run_overlay_loop(
    geometry: tuple[int, int, int, int],
    refresh_callback,
    interval_ms: int = 33,
) -> None:
    app = QApplication([])
    overlay = AimOverlay(geometry)

    def tick() -> None:
        data = refresh_callback()
        overlay.update_data(data)

    timer = QTimer()
    timer.timeout.connect(tick)
    timer.start(interval_ms)
    overlay.show()
    app.exec()
