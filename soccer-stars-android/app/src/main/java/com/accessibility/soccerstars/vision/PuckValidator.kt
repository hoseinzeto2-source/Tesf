package com.accessibility.soccerstars.vision

import android.graphics.Bitmap
import android.graphics.Color
import kotlin.math.PI
import kotlin.math.cos
import kotlin.math.hypot
import kotlin.math.sin

/**
 * Validates puck candidates using APK texture signatures:
 * white star center + silver rim + team color interior.
 */
class PuckValidator(private val colorMatcher: FieldColorMatcher) {

    data class ScoredPuck(
        val x: Double,
        val y: Double,
        val radius: Double,
        val team: String,
        val score: Int,
    )

    fun scoreCandidate(bitmap: Bitmap, x: Double, y: Double, radius: Double, team: String): Int {
        var score = 0
        if (hasStarCenter(bitmap, x, y, radius)) score += 28
        if (hasMetalRim(bitmap, x, y, radius)) score += 18
        if (hasTeamInterior(bitmap, x, y, radius, team)) score += 16
        if (isOnFieldTurf(bitmap, x.toInt(), y.toInt())) score -= 40
        if (isInUiZone(bitmap, x, y)) score -= 50
        return score
    }

    fun isValid(bitmap: Bitmap, x: Double, y: Double, radius: Double, team: String): Boolean {
        return scoreCandidate(bitmap, x, y, radius, team) >= 42
    }

    fun filterAndCap(candidates: List<ScoredPuck>, maxPerTeam: Int = 6): List<ScoredPuck> {
        if (candidates.isEmpty()) return emptyList()

        val radii = candidates.map { it.radius }
        val medianR = radii.sorted()[radii.size / 2]
        val minR = medianR * 0.62
        val maxR = medianR * 1.45

        val sized = candidates.filter { it.radius in minR..maxR && it.score >= 42 }
        val blue = sized.filter { it.team == "blue" }.sortedByDescending { it.score }.take(maxPerTeam)
        val red = sized.filter { it.team == "red" }.sortedByDescending { it.score }.take(maxPerTeam)
        return (blue + red).sortedByDescending { it.score }
    }

    fun teamAt(bitmap: Bitmap, color: Int, x: Int, y: Int): String? {
        if (colorMatcher.isFieldTurf(color)) return null
        if (colorMatcher.isFieldLine(color)) return null
        if (isAimGuide(color)) return null
        if (colorMatcher.isBallColor(color)) return null

        val hsv = hsv(color)
        val isBlue = hsv[0] in BLUE_H_MIN..BLUE_H_MAX &&
            hsv[1] >= BLUE_S_MIN && hsv[2] >= BLUE_V_MIN
        val isRed = (hsv[0] <= RED_H_MAX || hsv[0] >= RED_H_WRAP) &&
            hsv[1] >= RED_S_MIN && hsv[2] >= RED_V_MIN

        if (isBlue && !isRed) return "blue"
        if (isRed && !isBlue) return "red"
        if (isBlue && isRed) {
            return if (hsv[0] in BLUE_H_MIN..BLUE_H_MAX) "blue" else "red"
        }
        return null
    }

    private fun hasStarCenter(bitmap: Bitmap, cx: Double, cy: Double, radius: Double): Boolean {
        return countDisk(bitmap, cx.toInt(), cy.toInt(), radius * 0.38) { c ->
            colorMatcher.isBallColor(c)
        } >= 6
    }

    private fun hasMetalRim(bitmap: Bitmap, cx: Double, cy: Double, radius: Double): Boolean {
        var metal = 0
        val ringR = radius * 1.04
        for (i in 0 until 12) {
            val angle = 2 * PI * i / 12
            val x = (cx + cos(angle) * ringR).toInt()
            val y = (cy + sin(angle) * ringR).toInt()
            if (x in 0 until bitmap.width && y in 0 until bitmap.height) {
                val hsv = hsv(bitmap.getPixel(x, y))
                if (hsv[1] <= 0.30f && hsv[2] >= 0.50f) metal++
            }
        }
        return metal >= 4
    }

    private fun hasTeamInterior(bitmap: Bitmap, cx: Double, cy: Double, radius: Double, team: String): Boolean {
        var hits = 0
        var total = 0
        val r = radius * 0.72
        for (i in 0 until 10) {
            val angle = 2 * PI * i / 10
            val x = (cx + cos(angle) * r * 0.55).toInt()
            val y = (cy + sin(angle) * r * 0.55).toInt()
            if (x in 0 until bitmap.width && y in 0 until bitmap.height) {
                total++
                val t = teamAt(bitmap, bitmap.getPixel(x, y), x, y)
                if (t == team) hits++
            }
        }
        return total > 0 && hits >= 4
    }

    private fun isOnFieldTurf(bitmap: Bitmap, x: Int, y: Int): Boolean {
        if (x !in 0 until bitmap.width || y !in 0 until bitmap.height) return false
        return colorMatcher.isFieldTurf(bitmap.getPixel(x, y))
    }

    private fun isInUiZone(bitmap: Bitmap, x: Double, y: Double): Boolean {
        val h = bitmap.height
        val w = bitmap.width
        return y < h * 0.11 || y > h * 0.90 || x < w * 0.02 || x > w * 0.98
    }

    private fun isAimGuide(color: Int): Boolean {
        val hsv = hsv(color)
        val yellow = hsv[0] in ColorCalibration.YELLOW_H_MIN..ColorCalibration.YELLOW_H_MAX &&
            hsv[1] >= ColorCalibration.YELLOW_S_MIN && hsv[2] >= ColorCalibration.YELLOW_V_MIN
        val orange = hsv[0] in 14f..ColorCalibration.ORANGE_H_MAX &&
            hsv[1] >= ColorCalibration.ORANGE_S_MIN && hsv[2] >= ColorCalibration.ORANGE_V_MIN
        return yellow || orange
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

    private fun hsv(color: Int): FloatArray {
        val hsv = FloatArray(3)
        Color.colorToHSV(color, hsv)
        return hsv
    }

    companion object {
        // standard_blue_puck-hd
        const val BLUE_H_MIN = 192f
        const val BLUE_H_MAX = 238f
        const val BLUE_S_MIN = 0.28f
        const val BLUE_V_MIN = 0.20f

        // red team puck (vivid red, NOT orange/brown field)
        const val RED_H_MAX = 12f
        const val RED_H_WRAP = 342f
        const val RED_S_MIN = 0.42f
        const val RED_V_MIN = 0.28f
    }
}
