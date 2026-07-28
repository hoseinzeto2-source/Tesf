package com.accessibility.soccerstars

import android.annotation.SuppressLint
import android.content.Context
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.RectF
import android.view.MotionEvent
import android.view.View
import kotlin.math.max

/**
 * Draggable in-game HUD menu (ImGui-style) that floats above Soccer Stars.
 */
@SuppressLint("ViewConstructor")
class AssistHudMenu(context: Context) : View(context) {
    var assistEnabled: Boolean = true
        set(value) {
            field = value
            invalidate()
        }

    var debugEnabled: Boolean = false
        set(value) {
            field = value
            invalidate()
        }

    var onAssistToggle: (Boolean) -> Unit = {}
    var onDebugToggle: (Boolean) -> Unit = {}
    var onOpenSettings: () -> Unit = {}
    var onPositionChanged: (Int, Int) -> Unit = { _, _ -> }

    private var expanded = true
    private var dragOffsetX = 0f
    private var dragOffsetY = 0f
    private var dragging = false

    private val panelW = 300f
    private val collapsedH = 52f
    private val expandedH = 220f

    private val bgPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.argb(225, 14, 20, 30)
        style = Paint.Style.FILL
    }

    private val borderPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.argb(240, 27, 153, 139)
        strokeWidth = 2f
        style = Paint.Style.STROKE
    }

    private val titlePaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.argb(245, 27, 153, 139)
        textSize = 24f
        isFakeBoldText = true
    }

    private val labelPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.argb(235, 230, 230, 230)
        textSize = 22f
    }

    private val btnOnPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.argb(230, 27, 153, 139)
        style = Paint.Style.FILL
    }

    private val btnOffPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.argb(200, 55, 65, 80)
        style = Paint.Style.FILL
    }

    private val btnTextPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.WHITE
        textSize = 20f
    }

    override fun onDraw(canvas: Canvas) {
        super.onDraw(canvas)
        val h = if (expanded) expandedH else collapsedH
        val rect = RectF(0f, 0f, panelW, h)

        canvas.drawRoundRect(rect, 12f, 12f, bgPaint)
        canvas.drawRoundRect(rect, 12f, 12f, borderPaint)
        canvas.drawText("SS Assist", 14f, 34f, titlePaint)

        val toggleLabel = if (expanded) "▼" else "▲"
        canvas.drawText(toggleLabel, panelW - 28f, 34f, labelPaint)

        if (!expanded) return

        drawButton(canvas, 12f, 58f, panelW - 24f, 40f, assistEnabled, "راهنما: ${if (assistEnabled) "روشن" else "خاموش"}")
        drawButton(canvas, 12f, 108f, panelW - 24f, 40f, debugEnabled, "تشخیص مهره: ${if (debugEnabled) "روشن" else "خاموش"}")
        drawButton(canvas, 12f, 158f, panelW - 24f, 40f, true, "تنظیمات")
    }

    private fun drawButton(canvas: Canvas, left: Float, top: Float, w: Float, h: Float, on: Boolean, text: String) {
        val rect = RectF(left, top, left + w, top + h)
        canvas.drawRoundRect(rect, 8f, 8f, if (on) btnOnPaint else btnOffPaint)
        canvas.drawText(text, left + 12f, top + 26f, btnTextPaint)
    }

    @SuppressLint("ClickableViewAccessibility")
    override fun onTouchEvent(event: MotionEvent): Boolean {
        when (event.actionMasked) {
            MotionEvent.ACTION_DOWN -> {
                dragging = event.y < 44f && event.x < panelW - 48f
                dragOffsetX = event.rawX
                dragOffsetY = event.rawY
                if (!dragging) handleTap(event.x, event.y)
                return true
            }
            MotionEvent.ACTION_MOVE -> {
                if (dragging) {
                    val parent = parent as? View
                    val maxX = (parent?.width ?: width) - panelW.toInt()
                    val maxY = (parent?.height ?: height) - (if (expanded) expandedH else collapsedH).toInt()
                    val newX = (event.rawX - (width / 2f)).toInt().coerceIn(0, max(0, maxX))
                    val newY = (event.rawY - 26f).toInt().coerceIn(0, max(0, maxY))
                    onPositionChanged(newX, newY)
                }
                return true
            }
            MotionEvent.ACTION_UP -> {
                dragging = false
                return true
            }
        }
        return super.onTouchEvent(event)
    }

    private fun handleTap(x: Float, y: Float) {
        if (y < 44f && x > panelW - 48f) {
            expanded = !expanded
            requestLayout()
            invalidate()
            return
        }
        if (!expanded) return

        if (y in 58f..98f) {
            assistEnabled = !assistEnabled
            onAssistToggle(assistEnabled)
            invalidate()
        } else if (y in 108f..148f) {
            debugEnabled = !debugEnabled
            onDebugToggle(debugEnabled)
            invalidate()
        } else if (y in 158f..198f) {
            onOpenSettings()
        }
    }

    override fun onMeasure(widthMeasureSpec: Int, heightMeasureSpec: Int) {
        val h = if (expanded) expandedH.toInt() else collapsedH.toInt()
        setMeasuredDimension(panelW.toInt(), h)
    }
}
