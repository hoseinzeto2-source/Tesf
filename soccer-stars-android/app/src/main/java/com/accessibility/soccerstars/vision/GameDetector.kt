package com.accessibility.soccerstars.vision

import android.graphics.Bitmap
import android.graphics.Color
import com.accessibility.soccerstars.physics.CircleBody
import com.accessibility.soccerstars.physics.FieldBounds
import com.accessibility.soccerstars.physics.Vec2
import kotlin.math.hypot
import kotlin.math.max
import kotlin.math.min

data class AimState(
    val active: Boolean,
    val start: Pair<Double, Double>?,
    val end: Pair<Double, Double>?,
    val direction: Vec2,
    val power: Double,
)

data class FrameDetection(
    val bounds: FieldBounds?,
    val ball: CircleBody?,
    val pucks: List<CircleBody>,
    val aim: AimState,
)

class GameDetector(
    private val maxShotPower: Double = 140.0,
    private val puckRadius: Double = 22.0,
    private val ballRadius: Double = 12.0,
) {
    fun detect(bitmap: Bitmap): FrameDetection {
        val width = bitmap.width
        val height = bitmap.height
        val bounds = detectFieldBounds(bitmap, width, height)
        val ball = detectBall(bitmap, bounds)
        val pucks = detectPucks(bitmap, bounds, ball)
        val aim = detectAimLine(bitmap, pucks)
        return FrameDetection(bounds, ball, pucks, aim)
    }

    private fun detectFieldBounds(bitmap: Bitmap, width: Int, height: Int): FieldBounds {
        var minX = width
        var minY = height
        var maxX = 0
        var maxY = 0
        var found = false

        val step = 6
        var y = 0
        while (y < height) {
            var x = 0
            while (x < width) {
                if (isGreenPitch(bitmap.getPixel(x, y))) {
                    found = true
                    minX = min(minX, x)
                    minY = min(minY, y)
                    maxX = max(maxX, x)
                    maxY = max(maxY, y)
                }
                x += step
            }
            y += step
        }

        if (!found) {
            return FieldBounds(20.0, 80.0, width - 20.0, height - 80.0)
        }

        return FieldBounds(
            left = minX + 8.0,
            top = minY + 8.0,
            right = maxX - 8.0,
            bottom = maxY - 8.0,
        )
    }

    private fun detectBall(bitmap: Bitmap, bounds: FieldBounds): CircleBody? {
        val candidates = mutableListOf<Triple<Int, Int, Int>>()
        val step = 4
        var y = bounds.top.toInt()
        while (y < bounds.bottom) {
            var x = bounds.left.toInt()
            while (x < bounds.right) {
                if (isBallColor(bitmap.getPixel(x, y))) {
                    candidates += Triple(x, y, countBallCluster(bitmap, x, y))
                }
                x += step
            }
            y += step
        }

        val best = candidates.maxByOrNull { it.third } ?: return null
        return CircleBody(
            id = -1,
            kind = "ball",
            x = best.first.toDouble(),
            y = best.second.toDouble(),
            radius = ballRadius,
            mass = 1.0,
            restitution = 0.92,
        )
    }

    private fun countBallCluster(bitmap: Bitmap, cx: Int, cy: Int): Int {
        var count = 0
        for (dy in -8..8) {
            for (dx in -8..8) {
                val x = cx + dx
                val y = cy + dy
                if (x in 0 until bitmap.width && y in 0 until bitmap.height) {
                    if (isBallColor(bitmap.getPixel(x, y))) count++
                }
            }
        }
        return count
    }

    private fun detectPucks(
        bitmap: Bitmap,
        bounds: FieldBounds,
        ball: CircleBody?,
    ): List<CircleBody> {
        val candidates = mutableListOf<Triple<Int, Int, Int>>()
        val step = 5
        var y = bounds.top.toInt()
        while (y < bounds.bottom) {
            var x = bounds.left.toInt()
            while (x < bounds.right) {
                val pixel = bitmap.getPixel(x, y)
                if (!isGreenPitch(pixel) && !isBallColor(pixel) && !isYellowLine(pixel)) {
                    val saturation = saturation(pixel)
                    val brightness = brightness(pixel)
                    if (saturation > 0.25 && brightness in 0.2..0.9) {
                        candidates += Triple(x, y, colorClusterScore(bitmap, x, y))
                    }
                }
                x += step
            }
            y += step
        }

        val merged = mergeCandidates(candidates, minDistance = 28.0)
        return merged.mapIndexed { index, (x, y, _) ->
            if (ball != null && hypot(x - ball.x, y - ball.y) < puckRadius) {
                null
            } else {
                CircleBody(
                    id = index,
                    kind = "puck",
                    x = x,
                    y = y,
                    radius = puckRadius,
                    mass = 2.0,
                )
            }
        }.filterNotNull()
    }

    private fun mergeCandidates(
        candidates: List<Triple<Int, Int, Int>>,
        minDistance: Double,
    ): List<Triple<Double, Double, Int>> {
        val sorted = candidates.sortedByDescending { it.third }
        val kept = mutableListOf<Triple<Double, Double, Int>>()
        for ((x, y, score) in sorted) {
            val tooClose = kept.any { hypot(it.first - x, it.second - y) < minDistance }
            if (!tooClose) kept += Triple(x.toDouble(), y.toDouble(), score)
            if (kept.size >= 10) break
        }
        return kept
    }

    private fun colorClusterScore(bitmap: Bitmap, cx: Int, cy: Int): Int {
        var score = 0
        for (dy in -10..10 step 2) {
            for (dx in -10..10 step 2) {
                val x = cx + dx
                val y = cy + dy
                if (x in 0 until bitmap.width && y in 0 until bitmap.height) {
                    val pixel = bitmap.getPixel(x, y)
                    if (!isGreenPitch(pixel) && saturation(pixel) > 0.2) score++
                }
            }
        }
        return score
    }

    private fun detectAimLine(bitmap: Bitmap, pucks: List<CircleBody>): AimState {
        if (pucks.isEmpty()) return AimState(false, null, null, Vec2(0.0, 0.0), 0.0)

        val points = mutableListOf<Pair<Int, Int>>()
        val step = 3
        for (y in 0 until bitmap.height step step) {
            for (x in 0 until bitmap.width step step) {
                if (isYellowLine(bitmap.getPixel(x, y))) {
                    points += x to y
                }
            }
        }
        if (points.size < 12) return AimState(false, null, null, Vec2(0.0, 0.0), 0.0)

        var bestPuck: CircleBody? = null
        var bestStart: Pair<Int, Int>? = null
        var bestEnd: Pair<Int, Int>? = null
        var bestLength = 0.0

        for (puck in pucks) {
            val near = points.filter { hypot(it.first - puck.x, it.second - puck.y) < puck.radius * 1.8 }
            if (near.size < 8) continue

            val start = near.minByOrNull { hypot(it.first - puck.x, it.second - puck.y) } ?: continue
            val end = near.maxByOrNull {
                hypot(
                    (it.first - start.first).toDouble(),
                    (it.second - start.second).toDouble(),
                )
            } ?: continue

            val length = hypot(
                (end.first - start.first).toDouble(),
                (end.second - start.second).toDouble(),
            )
            if (length > bestLength) {
                bestLength = length
                bestPuck = puck
                bestStart = start
                bestEnd = end
            }
        }

        if (bestPuck == null || bestStart == null || bestEnd == null || bestLength < 16) {
            return AimState(false, null, null, Vec2(0.0, 0.0), 0.0)
        }

        val dx = bestEnd.first - bestStart.first
        val dy = bestEnd.second - bestStart.second
        val direction = Vec2(dx.toDouble(), dy.toDouble()).normalized()
        val shotDir = Vec2(-direction.x, -direction.y)
        return AimState(
            active = true,
            start = bestStart.first.toDouble() to bestStart.second.toDouble(),
            end = bestEnd.first.toDouble() to bestEnd.second.toDouble(),
            direction = shotDir,
            power = min(bestLength, maxShotPower),
        )
    }

    fun nearestPuckToAim(detection: FrameDetection): CircleBody? {
        if (!detection.aim.active || detection.pucks.isEmpty()) return null
        val (sx, sy) = detection.aim.start ?: return null
        return detection.pucks.minByOrNull { hypot(it.x - sx, it.y - sy) }
    }

    private fun isGreenPitch(color: Int): Boolean {
        val hsv = FloatArray(3)
        Color.colorToHSV(color, hsv)
        return hsv[0] in 70f..150f && hsv[1] > 0.2f && hsv[2] > 0.2f
    }

    private fun isBallColor(color: Int): Boolean {
        val hsv = FloatArray(3)
        Color.colorToHSV(color, hsv)
        return hsv[1] < 0.25f && hsv[2] > 0.75f
    }

    private fun isYellowLine(color: Int): Boolean {
        val hsv = FloatArray(3)
        Color.colorToHSV(color, hsv)
        return hsv[0] in 20f..45f && hsv[1] > 0.35f && hsv[2] > 0.45f
    }

    private fun saturation(color: Int): Double {
        val hsv = FloatArray(3)
        Color.colorToHSV(color, hsv)
        return hsv[1].toDouble()
    }

    private fun brightness(color: Int): Double {
        val hsv = FloatArray(3)
        Color.colorToHSV(color, hsv)
        return hsv[2].toDouble()
    }
}
