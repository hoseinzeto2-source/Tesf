package com.accessibility.soccerstars

import android.graphics.Bitmap
import android.graphics.PointF
import com.accessibility.soccerstars.physics.CircleBody
import com.accessibility.soccerstars.physics.FieldBounds
import com.accessibility.soccerstars.physics.PhysicsConfig
import com.accessibility.soccerstars.physics.PhysicsEngine
import com.accessibility.soccerstars.physics.ShotInput
import com.accessibility.soccerstars.vision.FrameDetection
import com.accessibility.soccerstars.vision.GameDetector
import kotlin.math.hypot
import kotlin.math.min

class AssistController private constructor(
    private val detector: GameDetector,
    private val physicsConfig: PhysicsConfig,
    private val physics: PhysicsEngine,
    private val config: AssistConfig,
) {
    constructor() : this(buildDefault())

    companion object {
        private fun buildDefault(): Quadruple {
            val cfg = PhysicsConfig.load()
            return Quadruple(
                detector = GameDetector(),
                physicsConfig = cfg,
                physics = PhysicsEngine(FieldBounds(0.0, 0.0, 1.0, 1.0), cfg),
                config = AssistConfig(maxShotPower = cfg.maxShotPower, maxShotSpeed = cfg.maxShotSpeed),
            )
        }
    }

    private data class Quadruple(
        val detector: GameDetector,
        val physicsConfig: PhysicsConfig,
        val physics: PhysicsEngine,
        val config: AssistConfig,
    )

    private constructor(parts: Quadruple) : this(
        parts.detector,
        parts.physicsConfig,
        parts.physics,
        parts.config,
    )
    fun process(bitmap: Bitmap): OverlayState {
        val detection = detector.detect(bitmap)
        val bounds = detection.bounds ?: return OverlayState(
            active = false,
            statusText = "زمین بازی پیدا نشد",
        )

        physics.bounds = bounds
        val shooter = detector.nearestPuckToAim(detection) ?: return OverlayState(
            active = false,
            statusText = "مهره را بگیرید و بکشید",
        )

        if (!detection.aim.active || detection.ball == null) {
            return OverlayState(active = false, statusText = "مهره را بگیرید و بکشید")
        }

        val shot = ShotInput(
            puckId = shooter.id,
            direction = detection.aim.direction,
            power = detection.aim.power,
            maxPower = config.maxShotPower,
            maxSpeed = config.maxShotSpeed,
        )

        val bodies = buildBodies(shooter, detection)
        val goalRect = goalRect(bounds, shooter, detection.ball)
        val result = physics.simulateShot(bodies, shot, goalRect)

        val ruler = buildRulerPoints(
            shooter.x,
            shooter.y,
            detection.aim.direction.x,
            detection.aim.direction.y,
            detection.aim.power,
        )

        val powerPercent = ((detection.aim.power / config.maxShotPower) * 100).toInt().coerceIn(0, 100)
        val status = when {
            result.goalScored -> "احتمال گل!"
            powerPercent > 85 -> "قدرت زیاد — دقت کنید"
            else -> "مسیر توپ پیش‌بینی شد"
        }

        return OverlayState(
            active = true,
            statusText = status,
            rulerPoints = ruler,
            puckPath = result.puckPath.map { PointF(it.first.toFloat(), it.second.toFloat()) },
            ballPath = result.ballPath.map { PointF(it.first.toFloat(), it.second.toFloat()) },
            goalPoint = result.ballPath.lastOrNull()?.let { PointF(it.first.toFloat(), it.second.toFloat()) },
            goalScored = result.goalScored,
            powerPercent = powerPercent,
        )
    }

    private fun buildBodies(shooter: CircleBody, detection: FrameDetection): List<CircleBody> {
        val others = detection.pucks
            .filter { it.id != shooter.id }
            .map { it.copyState() }
        return listOf(shooter.copyState(), detection.ball!!.copyState()) + others
    }

    private fun goalRect(bounds: FieldBounds, shooter: CircleBody, ball: CircleBody): DoubleArray {
        val top = bounds.top + (bounds.bottom - bounds.top) * config.goalTopRatio
        val bottom = bounds.top + (bounds.bottom - bounds.top) * config.goalBottomRatio
        val margin = config.goalMarginPx
        val shootUp = ball.y < shooter.y
        return if (shootUp) {
            doubleArrayOf(bounds.left + margin, top, bounds.left + margin * 3, bottom)
        } else {
            doubleArrayOf(bounds.right - margin * 3, top, bounds.right - margin, bottom)
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
        val total = min(config.rulerExtensionPx, 80.0 + power * 1.6)
        val steps = maxOf(2, (total / config.rulerTickStepPx).toInt())

        return (0..steps).map { i ->
            val dist = (total / steps) * i
            PointF(
                (startX + ux * dist).toFloat(),
                (startY + uy * dist).toFloat(),
            )
        }
    }
}

data class AssistConfig(
    val maxShotPower: Double = 140.0,
    val maxShotSpeed: Double = 28.0,
    val rulerExtensionPx: Double = 280.0,
    val rulerTickStepPx: Double = 18.0,
    val goalTopRatio: Double = 0.38,
    val goalBottomRatio: Double = 0.62,
    val goalMarginPx: Double = 8.0,
)
