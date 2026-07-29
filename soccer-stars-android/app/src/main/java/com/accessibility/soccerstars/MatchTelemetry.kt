package com.accessibility.soccerstars

/**
 * Live match telemetry for the ImGui HUD (vision-based, 2D physics).
 * Z is not used — Soccer Stars play field is top-down XY.
 */
data class PuckTelemetry(
    val id: Int,
    val team: String,
    val xNorm: Float,
    val yNorm: Float,
    val xPx: Float,
    val yPx: Float,
    val speed: Float? = null,
    val trailLength: Float? = null,
    val squashEstimate: Float? = null,
)

data class MatchTelemetry(
    val inMatch: Boolean = false,
    val aimActive: Boolean = false,
    val postShot: Boolean = false,
    val ballXNorm: Float? = null,
    val ballYNorm: Float? = null,
    val ballXPx: Float? = null,
    val ballYPx: Float? = null,
    val pucks: List<PuckTelemetry> = emptyList(),
    val blueCount: Int = 0,
    val redCount: Int = 0,
    val goalPredicted: Boolean = false,
    val powerPercent: Int = 0,
    val confidence: Float = 0f,
    val mapFamily: String? = null,
    val notes: List<String> = emptyList(),
)
