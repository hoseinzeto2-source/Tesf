package com.accessibility.soccerstars

import android.content.Context
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.DashPathEffect
import android.graphics.Paint
import android.graphics.Path
import android.graphics.PointF
import android.view.View

class GuideOverlayView(context: Context) : View(context) {
  var showLegend: Boolean = true
  private var state = OverlayState()

  private val rulerPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
    color = Color.argb(240, 255, 255, 255)
    strokeWidth = 4f
    style = Paint.Style.STROKE
  }

  private val rulerTickPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
    color = Color.argb(210, 255, 255, 255)
    strokeWidth = 2f
  }

  private val arrowPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
    color = Color.argb(245, 255, 80, 80)
    strokeWidth = 6f
    style = Paint.Style.STROKE
    strokeCap = Paint.Cap.ROUND
  }

  private val puckPathPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
    color = Color.argb(210, 255, 220, 0)
    strokeWidth = 4f
    style = Paint.Style.STROKE
    pathEffect = DashPathEffect(floatArrayOf(16f, 10f), 0f)
  }

  private val ballPathPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
    color = Color.argb(225, 0, 200, 255)
    strokeWidth = 6f
    style = Paint.Style.STROKE
    pathEffect = DashPathEffect(floatArrayOf(20f, 12f), 0f)
  }

  private val goalPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
    color = Color.argb(245, 0, 255, 120)
    strokeWidth = 8f
    style = Paint.Style.STROKE
  }

  private val goalFillPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
    color = Color.argb(130, 0, 255, 120)
    style = Paint.Style.FILL
  }

  private val textPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
    color = Color.WHITE
    textSize = 38f
    setShadowLayer(8f, 0f, 0f, Color.BLACK)
  }

  private val subTextPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
    color = Color.argb(235, 220, 220, 220)
    textSize = 30f
    setShadowLayer(5f, 0f, 0f, Color.BLACK)
  }

  private val legendPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
    color = Color.argb(200, 255, 255, 255)
    textSize = 24f
    setShadowLayer(4f, 0f, 0f, Color.BLACK)
  }

  fun updateState(newState: OverlayState) {
    state = newState
    invalidate()
  }

  override fun onDraw(canvas: Canvas) {
    super.onDraw(canvas)

    canvas.drawText(state.statusText, 24f, 58f, textPaint)
    if (state.active) {
      canvas.drawText("قدرت شلیک: ${state.powerPercent}%", 24f, 98f, subTextPaint)
    }

    if (!state.active) return

    drawPolyline(canvas, state.rulerPoints, rulerPaint)
    drawRulerTicks(canvas, state.rulerPoints)
    drawPolyline(canvas, state.puckPath, puckPathPaint)
    drawPolyline(
      canvas,
      state.ballPath,
      if (state.goalScored) goalPaint else ballPathPaint,
    )

    state.goalPoint?.let { goal ->
      if (state.goalScored) {
        canvas.drawCircle(goal.x, goal.y, 20f, goalFillPaint)
        canvas.drawCircle(goal.x, goal.y, 20f, goalPaint)
      }
    }

    if (state.rulerPoints.size >= 2) {
      val prev = state.rulerPoints[state.rulerPoints.size - 2]
      val end = state.rulerPoints.last()
      canvas.drawLine(prev.x, prev.y, end.x, end.y, arrowPaint)
    }

    if (showLegend && state.showLegend) {
      val y = height - 48f
      canvas.drawText("سفید=خط‌کش  آبی=مسیر توپ  زرد=مسیر مهره  سبز=گل", 24f, y, legendPaint)
    }
  }

  private fun drawPolyline(canvas: Canvas, points: List<PointF>, paint: Paint) {
    if (points.size < 2) return
    val path = Path()
    path.moveTo(points.first().x, points.first().y)
    for (i in 1 until points.size) path.lineTo(points[i].x, points[i].y)
    canvas.drawPath(path, paint)
  }

  private fun drawRulerTicks(canvas: Canvas, points: List<PointF>) {
    for (i in points.indices step 2) {
      val p = points[i]
      canvas.drawLine(p.x - 8f, p.y, p.x + 8f, p.y, rulerTickPaint)
      canvas.drawLine(p.x, p.y - 8f, p.x, p.y + 8f, rulerTickPaint)
    }
  }
}
