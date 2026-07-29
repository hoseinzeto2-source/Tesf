package com.accessibility.soccerstars

import android.content.Context
import android.graphics.Bitmap
import android.graphics.PointF
import android.graphics.RectF
import com.accessibility.soccerstars.physics.CircleBody
import com.accessibility.soccerstars.physics.FieldBounds
import com.accessibility.soccerstars.physics.PhysicsConfig
import com.accessibility.soccerstars.physics.PhysicsEngine
import com.accessibility.soccerstars.physics.ShotInput
import com.accessibility.soccerstars.vision.FrameDetection
import com.accessibility.soccerstars.vision.FramePairAnalyzer
import com.accessibility.soccerstars.vision.GameDetector
import com.accessibility.soccerstars.vision.ScenePhase
import java.io.File
import kotlin.math.hypot
import kotlin.math.min

class AssistController(
    context: Context,
    physicsPath: String,
    private val rulerExtensionPx: Double = 380.0,
    private val showPuckPath: Boolean = true,
    private val showEnemyPaths: Boolean = true,
    private val showLegend: Boolean = true,
) {
    private val physicsConfig: PhysicsConfig = run {
        val file = File(physicsPath)
        if (file.exists() && file.length() > 0) PhysicsConfig.load(file) else PhysicsConfig()
    }
    private val detector = GameDetector(context, maxShotPower = physicsConfig.maxShotPower)
    private val pairAnalyzer = FramePairAnalyzer(detector)
    private val physics = PhysicsEngine(FieldBounds(0.0, 0.0, 1.0, 1.0), physicsConfig)

    fun analyzePair(before: Bitmap, after: Bitmap): PairAnalysisState {
        val result = pairAnalyzer.analyze(before, after)
        val report = pairAnalyzer.formatReport(result)
        val shooter = result.shooter

        val overlay = if (shooter != null && result.before.aim.active) {
            process(before, 1f).copy(
                statusText = "تحلیل جفت عکس — مهره ${if (shooter.team == "blue") "آبی" else "قرمز"} ${shooter.distance.toInt()}px جابجا شد",
            )
        } else {
            buildState(result.before, 1f, assistEnabled = false).copy(
                statusText = "تحلیل جفت عکس — ${result.puckMovements.count { it.matched && it.distance > 15f }} مهره جابجا شد",
            )
        }

        return PairAnalysisState(
            result = result,
            report = report,
            overlay = overlay,
        )
    }

    data class PairAnalysisState(
        val result: FramePairAnalyzer.PairAnalysisResult,
        val report: String,
        val overlay: OverlayState,
    )

    fun process(bitmap: Bitmap, scale: Float): OverlayState =
        buildState(detector.detect(bitmap), scale, assistEnabled = true)

    fun detectOnly(bitmap: Bitmap, scale: Float): OverlayState =
        buildState(detector.detect(bitmap), scale, assistEnabled = false)

    fun analyzeFrame(bitmap: Bitmap): OverlayState =
        process(bitmap, 1f)

    private fun buildState(detection: FrameDetection, scale: Float, assistEnabled: Boolean): OverlayState {
        val inGameplay = detection.pucks.size >= 4 ||
            (detection.pucks.size >= 2 && detection.ball != null) ||
            detection.isPostShot

        if (detection.scene == ScenePhase.MENU_OR_HOME && !inGameplay) {
            return idle(
                "صفحه اصلی یا منو — وارد مسابقه Soccer Stars شوید",
                detection,
                scale,
            )
        }

        if (detection.bounds == null && !inGameplay) {
            return idle("زمین بازی پیدا نشد — Soccer Stars را در مسابقه باز کنید", detection, scale)
        }

        if (detection.bounds == null) {
            return idle(
                "مسابقه شناسایی شد · آبی:${detection.bluePuckCount} قرمز:${detection.redPuckCount}",
                detection,
                scale,
            )
        }

        val bounds = detection.bounds
        val scaledBounds = scaleBounds(bounds, scale)
        physics.bounds = scaledBounds

        if (!assistEnabled) {
            return idle("راهنما خاموش است — از نوار اعلان روشن کنید", detection, scale)
        }

        if (!detection.aim.active) {
            val msg = if (detection.isPostShot) {
                val fastest = detection.postShotMotions.maxByOrNull { it.speed }
                if (fastest != null) {
                    "بعد از شلیک — سریع‌ترین مهره ${fastest.team}: سرعت ${fastest.speed.toInt()}"
                } else {
                    "بعد از شلیک — آبی:${detection.bluePuckCount} قرمز:${detection.redPuckCount}"
                }
            } else {
                "مهره را بگیرید و بکشید · آبی:${detection.bluePuckCount} قرمز:${detection.redPuckCount}"
            }
            return idle(msg, detection, scale)
        }

        val shooter = detector.nearestPuckToAim(detection)
            ?: return idle("مهره را بگیرید و بکشید", detection, scale)
        val ball = detection.ball
            ?: return idle("توپ پیدا نشد — کیفیت تشخیص را بالا ببرید", detection, scale)

        val shot = ShotInput(
            puckId = shooter.id,
            direction = detection.aim.direction,
            power = detection.aim.power,
            maxPower = physicsConfig.maxShotPower,
            maxSpeed = physicsConfig.maxShotSpeed,
        )

        val bodies = buildBodies(shooter, ball, detection)
        val goalRect = goalRect(scaledBounds, shooter, ball)
        val result = physics.simulateShot(bodies, shot, goalRect)

        val ruler = buildRulerPoints(
            shooter.x * scale,
            shooter.y * scale,
            detection.aim.direction.x,
            detection.aim.direction.y,
            detection.aim.power,
        )

        val powerPercent = ((detection.aim.power / physicsConfig.maxShotPower) * 100)
            .toInt().coerceIn(0, 100)

        val enemyCount = detection.pucks.count { it.id != shooter.id }
        val status = when {
            result.goalScored -> "احتمال گل! · $enemyCount مهره حریف در محاسبه"
            powerPercent > 88 -> "قدرت زیاد — آرام‌تر بکشید"
            powerPercent < 15 -> "قدرت کم — بیشتر بکشید"
            else -> "مقصد توپ: (${result.finalBallPosition?.first?.toInt()}, ${result.finalBallPosition?.second?.toInt()})"
        }

        val finalBall = result.finalBallPosition?.let {
            PointF((it.first * scale).toFloat(), (it.second * scale).toFloat())
        }

        return OverlayState(
            active = true,
            statusText = status,
            rulerPoints = ruler,
            puckPath = if (showPuckPath) {
                result.puckPath.map { PointF((it.first * scale).toFloat(), (it.second * scale).toFloat()) }
            } else {
                emptyList()
            },
            ballPath = result.ballPath.map {
                PointF((it.first * scale).toFloat(), (it.second * scale).toFloat())
            },
            enemyPuckPaths = if (showEnemyPaths) {
                result.enemyPuckPaths.values.map { path ->
                    path.map { PointF((it.first * scale).toFloat(), (it.second * scale).toFloat()) }
                }
            } else {
                emptyList()
            },
            goalPoint = result.ballPath.lastOrNull()?.let {
                PointF((it.first * scale).toFloat(), (it.second * scale).toFloat())
            },
            finalBallPoint = finalBall,
            goalScored = result.goalScored,
            powerPercent = powerPercent,
            showLegend = showLegend,
            confidence = detection.confidence,
            debugPucks = detection.pucks.map { PointF((it.x * scale).toFloat(), (it.y * scale).toFloat()) },
            debugPuckTeams = detection.pucks.map { it.kind.removePrefix("puck_") },
            debugBall = PointF((ball.x * scale).toFloat(), (ball.y * scale).toFloat()),
            debugField = fieldRect(bounds, scale),
            scenePhase = detection.scene,
            bluePuckCount = detection.bluePuckCount,
            redPuckCount = detection.redPuckCount,
            analysisNotes = detection.analysisNotes,
            mapFamily = detection.mapFamily,
            telemetry = buildTelemetry(
                detection = detection,
                bounds = bounds,
                scale = scale,
                aimActive = true,
                goalPredicted = result.goalScored,
                powerPercent = powerPercent,
            ),
        )
    }

    private fun idle(message: String, detection: FrameDetection, scale: Float) = OverlayState(
        active = false,
        statusText = message,
        confidence = detection.confidence,
        debugPucks = if (detection.scene != ScenePhase.MENU_OR_HOME || detection.pucks.isNotEmpty()) {
            detection.pucks.map { PointF((it.x * scale).toFloat(), (it.y * scale).toFloat()) }
        } else {
            emptyList()
        },
        debugPuckTeams = detection.pucks.map { it.kind.removePrefix("puck_") },
        debugBall = detection.ball?.let { PointF((it.x * scale).toFloat(), (it.y * scale).toFloat()) },
        debugField = detection.bounds?.let { fieldRect(it, scale) },
        scenePhase = detection.scene,
        bluePuckCount = detection.bluePuckCount,
        redPuckCount = detection.redPuckCount,
        analysisNotes = detection.analysisNotes,
        mapFamily = detection.mapFamily,
        telemetry = buildTelemetry(
            detection = detection,
            bounds = detection.bounds,
            scale = scale,
            aimActive = detection.aim.active,
            goalPredicted = false,
            powerPercent = ((detection.aim.power / physicsConfig.maxShotPower) * 100).toInt().coerceIn(0, 100),
        ),
    )

    private fun buildTelemetry(
        detection: FrameDetection,
        bounds: FieldBounds?,
        scale: Float,
        aimActive: Boolean,
        goalPredicted: Boolean,
        powerPercent: Int,
    ): MatchTelemetry {
        val inMatch = detection.scene == ScenePhase.IN_MATCH ||
            detection.pucks.size >= 4 ||
            (detection.pucks.size >= 2 && detection.ball != null) ||
            detection.isPostShot

        val motionById = detection.postShotMotions.associateBy { it.puckId }

        val puckTelemetry = detection.pucks.map { puck ->
            val team = puck.kind.removePrefix("puck_")
            val motion = motionById[puck.id]
            val trail = motion?.trailLength?.toFloat()
            val squash = trail?.let { len ->
                ((len / puck.radius.toFloat()) * 35f).coerceIn(0f, 100f)
            }
            PuckTelemetry(
                id = puck.id,
                team = team,
                xNorm = normCoord(puck.x, bounds, isX = true),
                yNorm = normCoord(puck.y, bounds, isX = false),
                xPx = (puck.x * scale).toFloat(),
                yPx = (puck.y * scale).toFloat(),
                speed = motion?.speed?.toFloat(),
                trailLength = trail,
                squashEstimate = squash,
            )
        }

        val ball = detection.ball
        return MatchTelemetry(
            inMatch = inMatch,
            aimActive = aimActive,
            postShot = detection.isPostShot,
            ballXNorm = ball?.let { normCoord(it.x, bounds, isX = true) },
            ballYNorm = ball?.let { normCoord(it.y, bounds, isX = false) },
            ballXPx = ball?.let { (it.x * scale).toFloat() },
            ballYPx = ball?.let { (it.y * scale).toFloat() },
            pucks = puckTelemetry,
            blueCount = detection.bluePuckCount,
            redCount = detection.redPuckCount,
            goalPredicted = goalPredicted,
            powerPercent = powerPercent,
            confidence = detection.confidence,
            mapFamily = detection.mapFamily,
            notes = buildList {
                if (detection.isPostShot) {
                    val fastest = detection.postShotMotions.maxByOrNull { it.speed }
                    if (fastest != null) {
                        add("سریع‌ترین: ${fastest.team} v=${fastest.speed.toInt()}")
                    }
                }
                addAll(detection.analysisNotes.take(2))
            },
        )
    }

    private fun normCoord(value: Double, bounds: FieldBounds?, isX: Boolean): Float {
        if (bounds == null) return 0f
        val span = if (isX) bounds.right - bounds.left else bounds.bottom - bounds.top
        if (span < 1e-3) return 0f
        val norm = if (isX) (value - bounds.left) / span else (value - bounds.top) / span
        return norm.toFloat().coerceIn(0f, 1f)
    }

    private fun fieldRect(bounds: FieldBounds, scale: Float) = RectF(
        (bounds.left * scale).toFloat(),
        (bounds.top * scale).toFloat(),
        (bounds.right * scale).toFloat(),
        (bounds.bottom * scale).toFloat(),
    )

    private fun scaleBounds(bounds: FieldBounds, scale: Float): FieldBounds =
        FieldBounds(
            left = bounds.left * scale,
            top = bounds.top * scale,
            right = bounds.right * scale,
            bottom = bounds.bottom * scale,
        )

    private fun buildBodies(
        shooter: CircleBody,
        ball: CircleBody,
        detection: FrameDetection,
    ): List<CircleBody> {
        val shooterBody = shooter.copyState().apply { mass = physicsConfig.puckMass }
        val ballBody = ball.copyState().apply {
            mass = physicsConfig.ballMass
            restitution = physicsConfig.restitution
        }
        val others = detection.pucks
            .filter { it.id != shooter.id }
            .map { it.copyState().apply { mass = physicsConfig.puckMass } }
        return listOf(shooterBody, ballBody) + others
    }

    private fun goalRect(bounds: FieldBounds, shooter: CircleBody, ball: CircleBody): DoubleArray {
        val top = bounds.top + (bounds.bottom - bounds.top) * 0.36
        val bottom = bounds.top + (bounds.bottom - bounds.top) * 0.64
        val margin = 10.0 * (bounds.right - bounds.left) / 400.0
        val shootLeft = ball.x < (bounds.left + bounds.right) / 2
        return if (shootLeft) {
            doubleArrayOf(bounds.left + margin, top, bounds.left + margin * 4, bottom)
        } else {
            doubleArrayOf(bounds.right - margin * 4, top, bounds.right - margin, bottom)
        }
    }

    private fun buildRulerPoints(
        startX: Double,
        startY: Double,
        dirX: Double,
        dirY: Double,
        power: Double,
    ): List<PointF> {
        val length = hypot(dirX, dirY)
        if (length < 1e-6) return emptyList()

        val ux = dirX / length
        val uy = dirY / length
        val total = min(rulerExtensionPx, 100.0 + power * 2.0)
        val steps = maxOf(2, (total / 16.0).toInt())

        return (0..steps).map { i ->
            val dist = (total / steps) * i
            PointF(
                (startX + ux * dist).toFloat(),
                (startY + uy * dist).toFloat(),
            )
        }
    }
}
