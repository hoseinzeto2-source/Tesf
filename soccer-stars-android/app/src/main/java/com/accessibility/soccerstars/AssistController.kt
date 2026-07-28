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
import java.io.File
import kotlin.math.hypot
import kotlin.math.min

class AssistController(
    physicsPath: String,
    private val rulerExtensionPx: Double = 280.0,
    private val showPuckPath: Boolean = true,
) {
    private val physicsConfig: PhysicsConfig = run {
        val file = File(physicsPath)
        if (file.exists() && file.length() > 0) PhysicsConfig.load(file) else PhysicsConfig()
    }
    private val detector = GameDetector(maxShotPower = physicsConfig.maxShotPower)
    private val physics = PhysicsEngine(FieldBounds(0.0, 0.0, 1.0, 1.0), physicsConfig)

    fun process(bitmap: Bitmap, scale: Float): OverlayState {
        val detection = detector.detect(bitmap)
        val bounds = detection.bounds ?: return idle("زمین بازی پیدا نشد — Soccer Stars را باز کنید")

        val scaledBounds = scaleBounds(bounds, scale)
        physics.bounds = scaledBounds

        if (!detection.aim.active) {
            return idle("مهره را بگیرید و بکشید")
        }

        val shooter = detector.nearestPuckToAim(detection) ?: return idle("مهره را بگیرید و بکشید")
        val ball = detection.ball ?: return idle("توپ پیدا نشد")

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

        val status = when {
            result.goalScored -> "احتمال گل!"
            powerPercent > 88 -> "قدرت زیاد — آرام‌تر بکشید"
            powerPercent < 15 -> "قدرت کم — بیشتر بکشید"
            else -> "مسیر توپ آماده است"
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
            goalPoint = result.ballPath.lastOrNull()?.let {
                PointF((it.first * scale).toFloat(), (it.second * scale).toFloat())
            },
            goalScored = result.goalScored,
            powerPercent = powerPercent,
            showLegend = true,
        )
    }

    private fun idle(message: String) = OverlayState(active = false, statusText = message)

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
        val top = bounds.top + (bounds.bottom - bounds.top) * 0.38
        val bottom = bounds.top + (bounds.bottom - bounds.top) * 0.62
        val margin = 10.0 * (bounds.right - bounds.left) / 400.0
        val shootUp = ball.y < shooter.y
        return if (shootUp) {
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
        val total = min(rulerExtensionPx, 90.0 + power * 1.8)
        val steps = maxOf(2, (total / 18.0).toInt())

        return (0..steps).map { i ->
            val dist = (total / steps) * i
            PointF(
                (startX + ux * dist).toFloat(),
                (startY + uy * dist).toFloat(),
            )
        }
    }
}
