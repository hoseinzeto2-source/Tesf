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

  private val debugFieldPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
    color = Color.argb(140, 0, 255, 120)
    strokeWidth = 2f
    style = Paint.Style.STROKE
  }

  private val debugPuckPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
    color = Color.argb(160, 255, 120, 255)
    strokeWidth = 2f
    style = Paint.Style.STROKE
  }

  private val debugBallPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
    color = Color.argb(200, 255, 255, 255)
    strokeWidth = 3f
    style = Paint.Style.STROKE
  }

  private val hudBgPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
    color = Color.argb(210, 16, 24, 36)
    style = Paint.Style.FILL
  }

  private val hudBorderPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
    color = Color.argb(230, 27, 153, 139)
    strokeWidth = 2f
    style = Paint.Style.STROKE
  }

  private val legendPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
    color = Color.argb(200, 255, 255, 255)
    textSize = 22f
  }

  private val hudTextPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
    color = Color.argb(245, 27, 153, 139)
    textSize = 26f
    isFakeBoldText = true
  }

  var showDebug: Boolean = false

  fun updateState(newState: OverlayState) {
    state = newState
    invalidate()
  }

  fun clearDisplay() {
    state = OverlayState()
    invalidate()
  }

  override fun onDraw(canvas: Canvas) {
    super.onDraw(canvas)
    if (visibility != VISIBLE) return

    if (state.scenePhase == com.accessibility.soccerstars.vision.ScenePhase.MENU_OR_HOME && !state.active) {
      return
    }

    drawHudPanel(canvas)

    if (showDebug) {
      state.debugField?.let { canvas.drawRect(it, debugFieldPaint) }
      state.debugBall?.let { canvas.drawCircle(it.x, it.y, 14f, debugBallPaint) }
      for (p in state.debugPucks) {
        canvas.drawCircle(p.x, p.y, 20f, debugPuckPaint)
      }
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
      canvas.drawText("سفید=خط‌کش · آبی=توپ · زرد=مهره · سبز=گل", 24f, y, legendPaint)
    }
  }

  private fun drawHudPanel(canvas: Canvas) {
    val panelW = 340f
    val panelH = if (state.active) 118f else 78f
    val left = 20f
    val top = 20f
    val right = left + panelW
    val bottom = top + panelH

    canvas.drawRoundRect(left, top, right, bottom, 14f, 14f, hudBgPaint)
    canvas.drawRoundRect(left, top, right, bottom, 14f, 14f, hudBorderPaint)

    canvas.drawText("SS Assist HUD", left + 16f, top + 30f, hudTextPaint)
    canvas.drawText(state.statusText, left + 16f, top + 58f, subTextPaint)

    val conf = (state.confidence * 100).toInt()
    val scene = when (state.scenePhase) {
      com.accessibility.soccerstars.vision.ScenePhase.IN_MATCH -> "مسابقه"
      com.accessibility.soccerstars.vision.ScenePhase.MENU_OR_HOME -> "منو/صفحه اصلی"
      com.accessibility.soccerstars.vision.ScenePhase.UNKNOWN -> "نامشخص"
    }
    canvas.drawText("وضعیت: $scene · دقت: $conf%", left + 16f, top + 86f, legendPaint)

    if (state.active) {
      canvas.drawText("قدرت: ${state.powerPercent}%", left + 190f, top + 86f, legendPaint)
      val barLeft = left + 16f
      val barTop = top + 98f
      val barW = panelW - 32f
      canvas.drawRect(barLeft, barTop, barLeft + barW, barTop + 8f, rulerTickPaint)
      canvas.drawRect(barLeft, barTop, barLeft + barW * (state.powerPercent / 100f), barTop + 8f, arrowPaint)
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
