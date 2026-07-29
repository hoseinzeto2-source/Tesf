package com.accessibility.soccerstars

import android.graphics.PointF
import android.graphics.RectF
import com.accessibility.soccerstars.vision.ScenePhase

data class OverlayState(
    val active: Boolean = false,
    val statusText: String = "Soccer Stars را باز کنید",
    val rulerPoints: List<PointF> = emptyList(),
    val puckPath: List<PointF> = emptyList(),
    val ballPath: List<PointF> = emptyList(),
    val enemyPuckPaths: List<List<PointF>> = emptyList(),
    val goalPoint: PointF? = null,
    val finalBallPoint: PointF? = null,
    val goalScored: Boolean = false,
    val powerPercent: Int = 0,
    val showLegend: Boolean = true,
    val confidence: Float = 0f,
    val debugPucks: List<PointF> = emptyList(),
    val debugPuckTeams: List<String> = emptyList(),
    val debugBall: PointF? = null,
    val debugField: RectF? = null,
    val scenePhase: ScenePhase = ScenePhase.UNKNOWN,
    val bluePuckCount: Int = 0,
    val redPuckCount: Int = 0,
    val mapFamily: String? = null,
    val analysisNotes: List<String> = emptyList(),
    val telemetry: MatchTelemetry = MatchTelemetry(),
)
