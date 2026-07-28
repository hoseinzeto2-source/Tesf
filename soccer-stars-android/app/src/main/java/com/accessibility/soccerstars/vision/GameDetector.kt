package com.accessibility.soccerstars.vision

import android.graphics.Bitmap
import android.graphics.Color
import com.accessibility.soccerstars.physics.CircleBody
import com.accessibility.soccerstars.physics.FieldBounds
import com.accessibility.soccerstars.physics.Vec2
import kotlin.math.PI
import kotlin.math.abs
import kotlin.math.cos
import kotlin.math.hypot
import kotlin.math.max
import kotlin.math.min
import kotlin.math.sin

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
    val confidence: Float = 0f,
)

class GameDetector(
    private val maxShotPower: Double = 140.0,
) {
    private val tracker = DetectionTracker()

    fun detect(bitmap: Bitmap): FrameDetection {
        val width = bitmap.width
        val height = bitmap.height
        val bounds = detectFieldBounds(bitmap, width, height)
        val scale = ((bounds.right - bounds.left) / 360.0).coerceIn(0.55, 1.8)
        val puckRadius = 22.0 * scale
        val ballRadius = 12.0 * scale

        val ball = detectBall(bitmap, bounds, ballRadius)
        val pucks = detectPucks(bitmap, bounds, ball, puckRadius)
        val aim = detectAimLine(bitmap, pucks, maxShotPower)

        val raw = FrameDetection(bounds, ball, pucks, aim, confidence = scoreConfidence(bounds, ball, pucks, aim))
        return tracker.smooth(raw, puckRadius, ballRadius)
    }

    fun nearestPuckToAim(detection: FrameDetection): CircleBody? {
        if (!detection.aim.active || detection.pucks.isEmpty()) return null
        val (sx, sy) = detection.aim.start ?: return null
        return detection.pucks.minByOrNull { hypot(it.x - sx, it.y - sy) }
    }

    private fun scoreConfidence(
        bounds: FieldBounds?,
        ball: CircleBody?,
        pucks: List<CircleBody>,
        aim: AimState,
    ): Float {
        var score = 0f
        if (bounds != null) score += 0.2f
        if (ball != null) score += 0.25f
        when (pucks.size) {
            in 4..10 -> score += 0.35f
            in 2..12 -> score += 0.2f
        }
        if (aim.active) score += 0.2f
        return score.coerceIn(0f, 1f)
    }

    private fun detectFieldBounds(bitmap: Bitmap, width: Int, height: Int): FieldBounds {
        var minX = width
        var minY = height
        var maxX = 0
        var maxY = 0
        var found = false
        val step = max(4, width / 120)

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

        if (!found) return FieldBounds(20.0, height * 0.12, width - 20.0, height * 0.88)

        val padX = (maxX - minX) * 0.02
        val padY = (maxY - minY) * 0.02
        return FieldBounds(
            left = minX + padX,
            top = minY + padY,
            right = maxX - padX,
            bottom = maxY - padY,
        )
    }

    private fun detectBall(bitmap: Bitmap, bounds: FieldBounds, radius: Double): CircleBody? {
        val candidates = mutableListOf<CircleCandidate>()
        val step = max(3, (radius / 3).toInt())
        var y = bounds.top.toInt()
        while (y < bounds.bottom) {
            var x = bounds.left.toInt()
            while (x < bounds.right) {
                if (isBallColor(bitmap.getPixel(x, y))) {
                    val refined = refineCircle(bitmap, x, y, radius * 0.7, radius * 1.4, ::isBallColor)
                    if (refined != null) candidates += refined
                }
                x += step
            }
            y += step
        }
        return pickBestCircle(candidates, -1, "ball", 1.0)?.let {
            CircleBody(-1, "ball", it.x, it.y, it.radius, mass = 1.0, restitution = 0.92)
        }
    }

    private fun detectPucks(
        bitmap: Bitmap,
        bounds: FieldBounds,
        ball: CircleBody?,
        radius: Double,
    ): List<CircleBody> {
        val candidates = mutableListOf<CircleCandidate>()
        val step = max(3, (radius / 2.2).toInt())
        var y = bounds.top.toInt()
        while (y < bounds.bottom) {
            var x = bounds.left.toInt()
            while (x < bounds.right) {
                val pixel = bitmap.getPixel(x, y)
                if (isPuckPixel(pixel)) {
                    val refined = refineCircle(bitmap, x, y, radius * 0.7, radius * 1.35, ::isPuckInterior)
                    if (refined != null) {
                        val bonus = puckColorBonus(bitmap, refined.x.toInt(), refined.y.toInt())
                        candidates += refined.copy(score = refined.score + bonus)
                    }
                }
                x += step
            }
            y += step
        }

        val merged = mergeCandidates(candidates, radius * 1.5)
        return merged
            .sortedByDescending { it.score }
            .take(12)
            .mapIndexedNotNull { index, c ->
                if (ball != null && hypot(c.x - ball.x, c.y - ball.y) < radius + ball.radius) null
                else CircleBody(index, "puck", c.x, c.y, c.radius, mass = 2.0)
            }
    }

    private fun detectAimLine(bitmap: Bitmap, pucks: List<CircleBody>, maxPower: Double): AimState {
        if (pucks.isEmpty()) return AimState(false, null, null, Vec2(0.0, 0.0), 0.0)

        val points = mutableListOf<Pair<Int, Int>>()
        val step = max(2, bitmap.width / 280)
        for (y in 0 until bitmap.height step step) {
            for (x in 0 until bitmap.width step step) {
                if (isYellowLine(bitmap.getPixel(x, y))) points += x to y
            }
        }
        if (points.size < 8) return AimState(false, null, null, Vec2(0.0, 0.0), 0.0)

        var bestLength = 0.0
        var bestStart: Pair<Int, Int>? = null
        var bestEnd: Pair<Int, Int>? = null

        for (puck in pucks) {
            val near = points.filter { hypot(it.first - puck.x, it.second - puck.y) < puck.radius * 2.5 }
            if (near.size < 5) continue

            val centroidX = near.map { it.first }.average()
            val centroidY = near.map { it.second }.average()
            val covXX = near.sumOf { (it.first - centroidX) * (it.first - centroidX) }
            val covXY = near.sumOf { (it.first - centroidX) * (it.second - centroidY) }
            val covYY = near.sumOf { (it.second - centroidY) * (it.second - centroidY) }
            val angle = 0.5 * kotlin.math.atan2(2 * covXY, covXX - covYY)
            val dirX = cos(angle)
            val dirY = sin(angle)

            val projections = near.map {
                (it.first - centroidX) * dirX + (it.second - centroidY) * dirY
            }
            val minProj = projections.minOrNull() ?: continue
            val maxProj = projections.maxOrNull() ?: continue
            val length = maxProj - minProj

            if (length > bestLength) {
                bestLength = length
                val startProj = minProj
                val endProj = maxProj
                bestStart = (centroidX + dirX * startProj).toInt() to (centroidY + dirY * startProj).toInt()
                bestEnd = (centroidX + dirX * endProj).toInt() to (centroidY + dirY * endProj).toInt()
            }
        }

        if (bestStart == null || bestEnd == null || bestLength < 12) {
            return AimState(false, null, null, Vec2(0.0, 0.0), 0.0)
        }

        val dx = bestEnd.first - bestStart.first
        val dy = bestEnd.second - bestStart.second
        val direction = Vec2(dx.toDouble(), dy.toDouble()).normalized()
        return AimState(
            active = true,
            start = bestStart.first.toDouble() to bestStart.second.toDouble(),
            end = bestEnd.first.toDouble() to bestEnd.second.toDouble(),
            direction = Vec2(-direction.x, -direction.y),
            power = min(bestLength, maxPower),
        )
    }

    private data class CircleCandidate(val x: Double, val y: Double, val radius: Double, val score: Int)

    private fun refineCircle(
        bitmap: Bitmap,
        seedX: Int,
        seedY: Int,
        minR: Double,
        maxR: Double,
        predicate: (Int) -> Boolean,
    ): CircleCandidate? {
        var best: CircleCandidate? = null
        val radii = listOf(minR, (minR + maxR) / 2, maxR)
        for (r in radii) {
            var hits = 0
            var total = 0
            val steps = 16
            for (i in 0 until steps) {
                val angle = 2 * PI * i / steps
                val x = (seedX + cos(angle) * r).toInt()
                val y = (seedY + sin(angle) * r).toInt()
                if (x in 0 until bitmap.width && y in 0 until bitmap.height) {
                    total++
                    if (predicate(bitmap.getPixel(x, y))) hits++
                }
            }
            val innerHits = countDisk(bitmap, seedX, seedY, r * 0.45, predicate)
            val score = hits * 2 + innerHits
            if (total > 0 && hits >= steps / 3) {
                val candidate = CircleCandidate(seedX.toDouble(), seedY.toDouble(), r, score)
                if (best == null || candidate.score > best.score) best = candidate
            }
        }
        return best
    }

    private fun countDisk(bitmap: Bitmap, cx: Int, cy: Int, r: Double, predicate: (Int) -> Boolean): Int {
        var count = 0
        val rr = r * r
        val ri = r.toInt() + 1
        for (dy in -ri..ri) {
            for (dx in -ri..ri) {
                if (dx * dx + dy * dy > rr) continue
                val x = cx + dx
                val y = cy + dy
                if (x in 0 until bitmap.width && y in 0 until bitmap.height && predicate(bitmap.getPixel(x, y))) {
                    count++
                }
            }
        }
        return count
    }

    private fun pickBestCircle(
        candidates: List<CircleCandidate>,
        id: Int,
        kind: String,
        mass: Double,
    ): CircleBody? {
        val merged = mergeCandidates(candidates, 18.0)
        val best = merged.maxByOrNull { it.score } ?: return null
        return CircleBody(id, kind, best.x, best.y, best.radius, mass = mass)
    }

    private fun mergeCandidates(candidates: List<CircleCandidate>, minDistance: Double): List<CircleCandidate> {
        val sorted = candidates.sortedByDescending { it.score }
        val kept = mutableListOf<CircleCandidate>()
        for (c in sorted) {
            val tooClose = kept.any { hypot(it.x - c.x, it.y - c.y) < minDistance }
            if (!tooClose) kept += c
        }
        return kept
    }

    private fun isGreenPitch(color: Int): Boolean {
        val hsv = hsv(color)
        return hsv[0] in 65f..155f && hsv[1] > 0.18f && hsv[2] > 0.18f
    }

    private fun isBallColor(color: Int): Boolean {
        val hsv = hsv(color)
        return hsv[1] < 0.32f && hsv[2] > 0.68f
    }

    private fun isYellowLine(color: Int): Boolean {
        val hsv = hsv(color)
        return hsv[0] in 16f..52f && hsv[1] > 0.28f && hsv[2] > 0.38f
    }

    private fun isRedTeam(color: Int): Boolean {
        val hsv = hsv(color)
        return (hsv[0] <= 18f || hsv[0] >= 330f) && hsv[1] > 0.35f && hsv[2] > 0.25f
    }

    private fun isBlueTeam(color: Int): Boolean {
        val hsv = hsv(color)
        return hsv[0] in 185f..255f && hsv[1] > 0.3f && hsv[2] > 0.2f
    }

    private fun isPuckPixel(color: Int): Boolean {
        if (isGreenPitch(color) || isBallColor(color) || isYellowLine(color)) return false
        return isRedTeam(color) || isBlueTeam(color) || isSaturatedDisk(color)
    }

    private fun isPuckInterior(color: Int): Boolean {
        if (isGreenPitch(color) || isYellowLine(color)) return false
        return isRedTeam(color) || isBlueTeam(color) || isSaturatedDisk(color) || isBallColor(color).not() && hsv(color)[2] > 0.35f
    }

    private fun isSaturatedDisk(color: Int): Boolean {
        val hsv = hsv(color)
        return hsv[1] > 0.28f && hsv[2] in 0.22f..0.92f
    }

    private fun puckColorBonus(bitmap: Bitmap, cx: Int, cy: Int): Int {
        var bonus = 0
        for (dx in -2..2) {
            for (dy in -2..2) {
                val x = cx + dx
                val y = cy + dy
                if (x !in 0 until bitmap.width || y !in 0 until bitmap.height) continue
                val c = bitmap.getPixel(x, y)
                if (isRedTeam(c) || isBlueTeam(c)) bonus += 3
            }
        }
        return bonus
    }

    private fun hsv(color: Int): FloatArray {
        val hsv = FloatArray(3)
        Color.colorToHSV(color, hsv)
        return hsv
    }
}
