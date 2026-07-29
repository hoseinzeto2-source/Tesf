package com.accessibility.soccerstars

import android.content.Context
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.RectF
import android.view.View
import com.accessibility.soccerstars.vision.ScenePhase

/**
 * Mobile Dear ImGui-inspired telemetry HUD (Canvas overlay — separate from game process).
 */
class ImGuiHudView(context: Context) : View(context) {

    private var state = OverlayState()
    private var telemetry = MatchTelemetry()

    private val windowBg = Color.argb(235, 30, 30, 30)
    private val titleBg = Color.argb(255, 44, 44, 48)
    private val titleActive = Color.argb(255, 59, 130, 246)
    private val border = Color.argb(255, 69, 69, 69)
    private val text = Color.argb(255, 240, 240, 240)
    private val textDim = Color.argb(220, 160, 160, 160)
    private val accent = Color.argb(255, 91, 155, 213)
    private val good = Color.argb(255, 80, 200, 120)

    private val bgPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply { color = windowBg; style = Paint.Style.FILL }
    private val titlePaint = Paint(Paint.ANTI_ALIAS_FLAG).apply { style = Paint.Style.FILL }
    private val borderPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = border; style = Paint.Style.STROKE; strokeWidth = 2f
    }
    private val textPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply { color = text; textSize = 26f }
    private val dimPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply { color = textDim; textSize = 22f }
    private val headerPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = accent; textSize = 24f; isFakeBoldText = true
    }
    private val titleTextPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = text; textSize = 28f; isFakeBoldText = true
    }
    private val goodPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply { color = good; textSize = 26f }

    private val panelRect = RectF()
    private val corner = 8f
    private var contentHeight = 420f

    fun update(state: OverlayState) {
        this.state = state
        this.telemetry = state.telemetry
        contentHeight = computeContentHeight()
        requestLayout()
        invalidate()
    }

    fun clear() {
        state = OverlayState()
        telemetry = MatchTelemetry()
        invalidate()
    }

    override fun onMeasure(widthMeasureSpec: Int, heightMeasureSpec: Int) {
        val w = (340 * resources.displayMetrics.density).toInt()
        val h = (contentHeight * resources.displayMetrics.density).toInt().coerceAtLeast(120)
        setMeasuredDimension(w, h)
    }

    override fun onDraw(canvas: Canvas) {
        super.onDraw(canvas)
        if (visibility != VISIBLE) return

        val inMatch = telemetry.inMatch || state.scenePhase == ScenePhase.IN_MATCH
        if (!inMatch) {
            drawMini(canvas, "خارج از مسابقه")
            return
        }

        val density = resources.displayMetrics.density
        val pad = 12f * density
        panelRect.set(0f, 0f, width.toFloat(), height.toFloat())
        canvas.drawRoundRect(panelRect, corner, corner, bgPaint)
        canvas.drawRoundRect(panelRect, corner, corner, borderPaint)

        val left = pad
        val titleH = 44f * density

        val titleRect = RectF(0f, 0f, width.toFloat(), titleH)
        titlePaint.color = if (telemetry.aimActive) titleActive else titleBg
        canvas.drawRoundRect(titleRect, corner, corner, titlePaint)
        canvas.drawRect(0f, titleH - corner, width.toFloat(), titleH, titlePaint)
        canvas.drawText("SS Assist · ImGui HUD", left, titleH * 0.65f, titleTextPaint)

        var y = titleH + pad

        y = drawSection(canvas, left, y, "وضعیت مسابقه") {
            row("فاز", sceneLabel(state.scenePhase))
            row("دقت", "${(telemetry.confidence * 100).toInt()}%")
            row("زمین", telemetry.mapFamily ?: "—")
            row("آبی/قرمز", "${telemetry.blueCount}/${telemetry.redCount}")
            row(
                "aim",
                when {
                    telemetry.aimActive -> "کشیدن"
                    telemetry.postShot -> "بعد شلیک"
                    else -> "آماده"
                },
            )
            row("قدرت", "${telemetry.powerPercent}%")
            row("گل پیش‌بینی", if (telemetry.goalPredicted) "بله" else "خیر", highlight = telemetry.goalPredicted)
        }

        y = drawSection(canvas, left, y, "توپ (X,Y)") {
            if (telemetry.ballXNorm != null && telemetry.ballYNorm != null) {
                row("نرمال", fmt(telemetry.ballXNorm!!, telemetry.ballYNorm!!))
                row("پیکسل", fmtPx(telemetry.ballXPx, telemetry.ballYPx))
            } else {
                row("—", "توپ دیده نشد")
            }
            row("Z فیزیک", "ندارد (2D)")
        }

        y = drawSection(canvas, left, y, "مهره‌ها") {
            if (telemetry.pucks.isEmpty()) {
                row("—", "هیچ مهره")
            } else {
                telemetry.pucks.take(6).forEach { p ->
                    val extra = buildList {
                        if (p.speed != null) add("v=${p.speed!!.toInt()}")
                        if (p.squashEstimate != null) add("squash=${p.squashEstimate!!.toInt()}%")
                    }.joinToString(" ")
                    row("${p.team}#${p.id}", "${fmt(p.xNorm, p.yNorm)} $extra")
                }
                if (telemetry.pucks.size > 6) {
                    dimRow("…", "+${telemetry.pucks.size - 6} بیشتر")
                }
            }
        }

        if (telemetry.postShot || telemetry.notes.isNotEmpty()) {
            y = drawSection(canvas, left, y, "حرکت / شکل") {
                if (telemetry.postShot) dimRow("trail", "تخمین blur حرکت")
                telemetry.notes.take(3).forEach { dimRow("•", it) }
            }
        }

        canvas.drawText("safe: overlay جدا — بازی کرش نمی‌کند", left, height - pad, dimPaint)
    }

    private fun drawMini(canvas: Canvas, msg: String) {
        panelRect.set(0f, 0f, width.toFloat(), height.toFloat())
        canvas.drawRoundRect(panelRect, corner, corner, bgPaint)
        canvas.drawRoundRect(panelRect, corner, corner, borderPaint)
        canvas.drawText(msg, 16f, 36f, dimPaint)
    }

    private fun drawSection(
        canvas: Canvas,
        left: Float,
        startY: Float,
        title: String,
        block: SectionBuilder.() -> Unit,
    ): Float {
        val builder = SectionBuilder()
        block(builder)
        var y = startY
        canvas.drawText(title, left, y, headerPaint)
        y += 30f
        for (line in builder.lines) {
            val paint = when {
                line.dim -> dimPaint
                line.highlight -> goodPaint
                else -> textPaint
            }
            canvas.drawText("${line.label}: ${line.value}", left, y, paint)
            y += 26f
        }
        return y + 8f
    }

    private class SectionBuilder {
        val lines = mutableListOf<Line>()
        fun row(label: String, value: String, highlight: Boolean = false) {
            lines += Line(label, value, highlight, false)
        }
        fun dimRow(label: String, value: String) {
            lines += Line(label, value, false, true)
        }
    }

    private data class Line(val label: String, val value: String, val highlight: Boolean, val dim: Boolean)

    private fun computeContentHeight(): Float {
        var h = 44f + 24f
        h += 7 * 26f
        h += 4 * 26f
        h += (telemetry.pucks.take(6).size.coerceAtLeast(1)) * 26f
        h += telemetry.notes.take(3).size * 24f
        h += 60f
        return h.coerceIn(180f, 520f)
    }

    private fun sceneLabel(phase: ScenePhase): String = when (phase) {
        ScenePhase.IN_MATCH -> "مسابقه"
        ScenePhase.MENU_OR_HOME -> "منو"
        ScenePhase.UNKNOWN -> "نامشخص"
    }

    private fun fmt(x: Float, y: Float) = String.format("%.3f, %.3f", x, y)

    private fun fmtPx(x: Float?, y: Float?) =
        if (x != null && y != null) String.format("%.0f, %.0f", x, y) else "—"
}
