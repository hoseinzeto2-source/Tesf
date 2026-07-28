package com.accessibility.soccerstars

import android.content.Context
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.DashPathEffect
import android.graphics.Paint
import android.graphics.Path
import android.graphics.PointF
import android.util.AttributeSet
import android.view.View

/**
 * Draws a screenshot with analysis overlay for the Lab screen.
 */
class LabPreviewView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null,
) : View(context, attrs) {
    private var bitmap: Bitmap? = null
    private var state = OverlayState()

    private val rulerPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = 0xF0FFFFFF.toInt()
        strokeWidth = 3f
        style = Paint.Style.STROKE
    }

    private val ballPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = 0xE000C8FF.toInt()
        strokeWidth = 4f
        style = Paint.Style.STROKE
        pathEffect = DashPathEffect(floatArrayOf(14f, 8f), 0f)
    }

    private val puckPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = 0xD0FFDC00.toInt()
        strokeWidth = 3f
        style = Paint.Style.STROKE
        pathEffect = DashPathEffect(floatArrayOf(12f, 8f), 0f)
    }

    private val enemyPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = 0xD0FF6464.toInt()
        strokeWidth = 2f
        style = Paint.Style.STROKE
        pathEffect = DashPathEffect(floatArrayOf(8f, 6f), 0f)
    }

    private val goalPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = 0xFF00FF78.toInt()
        strokeWidth = 6f
        style = Paint.Style.STROKE
    }

    private val debugBlue = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = 0xCC5090FF.toInt()
        strokeWidth = 2f
        style = Paint.Style.STROKE
    }

    private val debugRed = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = 0xCCFF5050.toInt()
        strokeWidth = 2f
        style = Paint.Style.STROKE
    }

    fun setImage(source: Bitmap?) {
        bitmap = source
        invalidate()
    }

    fun setAnalysis(result: OverlayState) {
        state = result
        invalidate()
    }

    override fun onDraw(canvas: Canvas) {
        super.onDraw(canvas)
        val bmp = bitmap
        if (bmp == null) {
            val hint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
                color = 0xFFAAAAAA.toInt()
                textSize = 36f
            }
            canvas.drawText("عکس شلیک را انتخاب کنید", 40f, height / 2f, hint)
            return
        }

        val scale = minOf(width.toFloat() / bmp.width, height.toFloat() / bmp.height)
        val drawW = bmp.width * scale
        val drawH = bmp.height * scale
        val left = (width - drawW) / 2f
        val top = (height - drawH) / 2f

        canvas.save()
        canvas.translate(left, top)
        canvas.scale(scale, scale)
        canvas.drawBitmap(bmp, 0f, 0f, null)

        drawPolyline(canvas, state.rulerPoints, rulerPaint)
        drawPolyline(canvas, state.puckPath, puckPaint)
        for (path in state.enemyPuckPaths) drawPolyline(canvas, path, enemyPaint)
        drawPolyline(canvas, state.ballPath, ballPaint)

        state.finalBallPoint?.let {
            canvas.drawCircle(it.x, it.y, 14f, goalPaint)
        }

        state.debugPucks.forEachIndexed { i, p ->
            val paint = when (state.debugPuckTeams.getOrNull(i)) {
                "blue" -> debugBlue
                "red" -> debugRed
                else -> debugBlue
            }
            canvas.drawCircle(p.x, p.y, 18f, paint)
        }
        state.debugBall?.let { canvas.drawCircle(it.x, it.y, 12f, goalPaint) }
        state.debugField?.let { canvas.drawRect(it, debugBlue) }

        canvas.restore()
    }

    private fun drawPolyline(canvas: Canvas, points: List<PointF>, paint: Paint) {
        if (points.size < 2) return
        val path = Path()
        path.moveTo(points.first().x, points.first().y)
        for (i in 1 until points.size) path.lineTo(points[i].x, points[i].y)
        canvas.drawPath(path, paint)
    }
}
