package com.accessibility.soccerstars.vision

import android.graphics.Bitmap
import android.graphics.Color
import com.accessibility.soccerstars.physics.CircleBody
import com.accessibility.soccerstars.physics.Vec2
import kotlin.math.PI
import kotlin.math.atan2
import kotlin.math.cos
import kotlin.math.hypot
import kotlin.math.sin

/**
 * Estimates post-shot puck velocity from motion trails / blur streaks in a single frame.
 */
class MotionTrailDetector(private val validator: PuckValidator) {

    data class MotionEstimate(
        val puckId: Int,
        val team: String,
        val speed: Double,
        val direction: Vec2,
        val trailLength: Double,
    )

    fun detect(bitmap: Bitmap, pucks: List<CircleBody>): List<MotionEstimate> {
        val results = mutableListOf<MotionEstimate>()
        for (puck in pucks) {
            val team = puck.kind.removePrefix("puck_")
            val trail = scanTrail(bitmap, puck.x, puck.y, puck.radius, team)
            if (trail != null && trail.third >= puck.radius * 0.55) {
                val (dirX, dirY, length) = trail
                val speed = (length / puck.radius * 12.0).coerceIn(0.0, 30.0)
                results += MotionEstimate(
                    puckId = puck.id,
                    team = team,
                    speed = speed,
                    direction = Vec2(dirX, dirY),
                    trailLength = length,
                )
            }
        }
        return results.sortedByDescending { it.speed }
    }

    private fun scanTrail(
        bitmap: Bitmap,
        cx: Double,
        cy: Double,
        radius: Double,
        team: String,
    ): Triple<Double, Double, Double>? {
        var bestLen = 0.0
        var bestDx = 0.0
        var bestDy = 0.0

        val steps = 16
        for (i in 0 until steps) {
            val angle = 2 * PI * i / steps
            val dirX = cos(angle)
            val dirY = sin(angle)
            var len = 0.0
            var hits = 0
            val maxDist = radius * 2.8
            var d = radius * 0.9
            while (d < maxDist) {
                val x = (cx + dirX * d).toInt()
                val y = (cy + dirY * d).toInt()
                if (x !in 0 until bitmap.width || y !in 0 until bitmap.height) break
                val pixel = bitmap.getPixel(x, y)
                val t = validator.teamAt(bitmap, pixel, x, y)
                if (t == team || isMotionBlur(pixel)) {
                    hits++
                    len = d
                } else if (hits >= 2) {
                    break
                }
                d += radius * 0.22
            }
            if (len > bestLen && hits >= 2) {
                bestLen = len
                bestDx = dirX
                bestDy = dirY
            }
        }

        if (bestLen < radius * 0.5) return null
        return Triple(bestDx, bestDy, bestLen)
    }

    private fun isMotionBlur(color: Int): Boolean {
        val a = Color.alpha(color)
        val hsv = FloatArray(3)
        Color.colorToHSV(color, hsv)
        return a in 80..220 && hsv[1] <= 0.55f && hsv[2] in 0.25f..0.85f
    }
}
