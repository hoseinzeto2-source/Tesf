package com.accessibility.soccerstars

import android.graphics.PointF

data class OverlayState(
    val active: Boolean = false,
    val statusText: String = "Soccer Stars را باز کنید",
    val rulerPoints: List<PointF> = emptyList(),
    val puckPath: List<PointF> = emptyList(),
    val ballPath: List<PointF> = emptyList(),
    val goalPoint: PointF? = null,
    val goalScored: Boolean = false,
    val powerPercent: Int = 0,
    val showLegend: Boolean = true,
)
