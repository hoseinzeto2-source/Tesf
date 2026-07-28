package com.accessibility.soccerstars

import android.content.Context
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.RectF
import android.view.View

/**
 * Small always-visible, non-touchable status strip so the user knows assist is running.
 * Controls remain in the notification shade — this does not block touches.
 */
class StatusBadgeView(context: Context) : View(context) {
    var statusText: String = "SS Assist فعال"
        set(value) {
            field = value
            invalidate()
        }

    private val bgPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.argb(220, 16, 24, 36)
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

    private val textPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.argb(235, 240, 240, 240)
        textSize = 20f
    }

    private val hintPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.argb(200, 180, 200, 200)
        textSize = 17f
    }

    override fun onDraw(canvas: Canvas) {
        super.onDraw(canvas)
        val w = width.toFloat()
        val h = height.toFloat()
        val rect = RectF(0f, 0f, w, h)
        canvas.drawRoundRect(rect, 16f, 16f, bgPaint)
        canvas.drawRoundRect(rect, 16f, 16f, borderPaint)
        canvas.drawText("SS Assist", 14f, 28f, titlePaint)
        canvas.drawText(statusText, 14f, 54f, textPaint)
        canvas.drawText("کنترل: نوار اعلان را پایین بکشید ▼", 14f, 76f, hintPaint)
    }

    override fun onMeasure(widthMeasureSpec: Int, heightMeasureSpec: Int) {
        setMeasuredDimension(340, 88)
    }
}
