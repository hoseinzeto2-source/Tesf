package com.accessibility.soccerstars.vision

import android.graphics.Bitmap
import android.graphics.PointF
import com.accessibility.soccerstars.physics.CircleBody
import kotlin.math.hypot
import kotlin.math.roundToInt

/**
 * Compares before/after screenshots of the same match to report exact puck/ball movement.
 */
class FramePairAnalyzer(
    private val gameDetector: GameDetector,
) {

    data class PuckMovement(
        val team: String,
        val before: PointF,
        val after: PointF,
        val displacement: PointF,
        val distance: Float,
        val beforeRadius: Float,
        val afterRadius: Float,
        val matched: Boolean,
    )

    data class BallMovement(
        val before: PointF?,
        val after: PointF?,
        val displacement: PointF?,
        val distance: Float,
    )

    data class MotionRegion(
        val center: PointF,
        val radius: Float,
        val intensity: Float,
    )

    data class PairAnalysisResult(
        val before: FrameDetection,
        val after: FrameDetection,
        val puckMovements: List<PuckMovement>,
        val ballMovement: BallMovement?,
        val shooter: PuckMovement?,
        val motionRegions: List<MotionRegion>,
    )

    fun analyze(before: Bitmap, after: Bitmap): PairAnalysisResult {
        val beforeResult = gameDetector.analyzeGameplay(before)
        val afterResult = gameDetector.analyzeGameplay(after)

        val puckMovements = PuckMovementMatcher.match(beforeResult.pucks, afterResult.pucks)
        val ballMovement = matchBall(beforeResult.ball, afterResult.ball)
        val shooter = puckMovements
            .filter { it.matched && it.distance > MIN_SHOT_DISTANCE }
            .maxByOrNull { it.distance }

        val motionRegions = detectMotionRegions(before, after, beforeResult.bounds)

        return PairAnalysisResult(
            before = beforeResult,
            after = afterResult,
            puckMovements = puckMovements,
            ballMovement = ballMovement,
            shooter = shooter,
            motionRegions = motionRegions,
        )
    }

    private fun matchBall(before: CircleBody?, after: CircleBody?): BallMovement? {
        if (before == null && after == null) return null
        if (before == null) {
            return BallMovement(
                before = null,
                after = PointF(after!!.x.toFloat(), after.y.toFloat()),
                displacement = null,
                distance = 0f,
            )
        }
        if (after == null) {
            return BallMovement(
                before = PointF(before.x.toFloat(), before.y.toFloat()),
                after = null,
                displacement = null,
                distance = 0f,
            )
        }
        val dx = (after.x - before.x).toFloat()
        val dy = (after.y - before.y).toFloat()
        return BallMovement(
            before = PointF(before.x.toFloat(), before.y.toFloat()),
            after = PointF(after.x.toFloat(), after.y.toFloat()),
            displacement = PointF(dx, dy),
            distance = hypot(dx.toDouble(), dy.toDouble()).toFloat(),
        )
    }

    private fun detectMotionRegions(
        before: Bitmap,
        after: Bitmap,
        fieldBounds: com.accessibility.soccerstars.physics.FieldBounds?,
    ): List<MotionRegion> {
        if (before.width != after.width || before.height != after.height) return emptyList()

        val w = before.width
        val h = before.height
        val step = 6
        val regions = mutableListOf<MotionRegion>()

        val left = fieldBounds?.left?.toInt() ?: 0
        val top = fieldBounds?.top?.toInt() ?: 0
        val right = fieldBounds?.right?.toInt() ?: w
        val bottom = fieldBounds?.bottom?.toInt() ?: h

        var y = top
        while (y < bottom) {
            var x = left
            while (x < right) {
                val diff = colorDiff(before.getPixel(x, y), after.getPixel(x, y))
                if (diff > 45) {
                    regions += MotionRegion(
                        center = PointF(x.toFloat(), y.toFloat()),
                        radius = step.toFloat(),
                        intensity = diff / 255f,
                    )
                }
                x += step
            }
            y += step
        }

        return mergeNearbyRegions(regions, 40f).take(20)
    }

    private fun colorDiff(c1: Int, c2: Int): Int {
        val dr = kotlin.math.abs(android.graphics.Color.red(c1) - android.graphics.Color.red(c2))
        val dg = kotlin.math.abs(android.graphics.Color.green(c1) - android.graphics.Color.green(c2))
        val db = kotlin.math.abs(android.graphics.Color.blue(c1) - android.graphics.Color.blue(c2))
        return (dr + dg + db) / 3
    }

    private fun mergeNearbyRegions(regions: List<MotionRegion>, mergeDist: Float): List<MotionRegion> {
        if (regions.isEmpty()) return emptyList()
        val merged = mutableListOf<MotionRegion>()
        val used = BooleanArray(regions.size)

        for (i in regions.indices) {
            if (used[i]) continue
            var cx = regions[i].center.x * regions[i].intensity
            var cy = regions[i].center.y * regions[i].intensity
            var totalWeight = regions[i].intensity
            var maxIntensity = regions[i].intensity
            used[i] = true

            for (j in i + 1 until regions.size) {
                if (used[j]) continue
                val d = hypot(
                    regions[j].center.x - regions[i].center.x,
                    regions[j].center.y - regions[i].center.y,
                )
                if (d <= mergeDist) {
                    used[j] = true
                    cx += regions[j].center.x * regions[j].intensity
                    cy += regions[j].center.y * regions[j].intensity
                    totalWeight += regions[j].intensity
                    maxIntensity = maxOf(maxIntensity, regions[j].intensity)
                }
            }

            merged += MotionRegion(
                center = PointF(cx / totalWeight, cy / totalWeight),
                radius = mergeDist,
                intensity = maxIntensity,
            )
        }
        return merged.sortedByDescending { it.intensity }
    }

    fun formatReport(result: PairAnalysisResult): String {
        val sb = StringBuilder()
        sb.appendLine("═══ تحلیل جفت عکس (قبل/بعد شلیک) ═══")
        sb.appendLine()

        val b = result.before
        val a = result.after
        sb.appendLine("📷 قبل: ${b.pucks.size} مهره | بعد: ${a.pucks.size} مهره")
        sb.appendLine("   آبی قبل=${b.bluePuckCount} | قرمز قبل=${b.redPuckCount}")
        sb.appendLine("   آبی بعد=${a.bluePuckCount} | قرمز بعد=${a.redPuckCount}")
        result.before.mapFamily?.let { sb.appendLine("   نوع مپ: $it") }
        sb.appendLine()

        result.shooter?.let { s ->
            val teamName = if (s.team == "blue") "آبی" else "قرمز"
            sb.appendLine("🎯 مهره شلیک‌کننده: $teamName")
            sb.appendLine("   قبل: (${s.before.x.round()}, ${s.before.y.round()})")
            sb.appendLine("   بعد: (${s.after.x.round()}, ${s.after.y.round()})")
            sb.appendLine("   جابجایی: ${s.distance.round()}px")
            val angle = Math.toDegrees(
                kotlin.math.atan2(s.displacement.y.toDouble(), s.displacement.x.toDouble()),
            ).roundToInt()
            sb.appendLine("   زاویه حرکت: ${angle}°")
            sb.appendLine()
        }

        sb.appendLine("── موقعیت مهره‌ها ──")
        val blueMoves = result.puckMovements.filter { it.team == "blue" }
        val redMoves = result.puckMovements.filter { it.team == "red" }

        sb.appendLine("🔵 آبی (${blueMoves.size}):")
        for ((i, m) in blueMoves.withIndex()) {
            sb.appendLine(
                "  ${i + 1}. قبل(${m.before.x.round()},${m.before.y.round()}) → " +
                    "بعد(${m.after.x.round()},${m.after.y.round()}) | ${m.distance.round()}px",
            )
        }

        sb.appendLine("🔴 قرمز (${redMoves.size}):")
        for ((i, m) in redMoves.withIndex()) {
            sb.appendLine(
                "  ${i + 1}. قبل(${m.before.x.round()},${m.before.y.round()}) → " +
                    "بعد(${m.after.x.round()},${m.after.y.round()}) | ${m.distance.round()}px",
            )
        }
        sb.appendLine()

        result.ballMovement?.let { ball ->
            sb.appendLine("⚽ توپ:")
            ball.before?.let { sb.appendLine("   قبل: (${it.x.round()}, ${it.y.round()})") }
            ball.after?.let { sb.appendLine("   بعد: (${it.x.round()}, ${it.y.round()})") }
            if (ball.distance > 1f) {
                sb.appendLine("   جابجایی: ${ball.distance.round()}px")
            }
            sb.appendLine()
        }

        if (result.motionRegions.isNotEmpty()) {
            sb.appendLine("💨 نواحی حرکت (تفاوت پیکسلی): ${result.motionRegions.size}")
            result.motionRegions.take(5).forEachIndexed { i, r ->
                sb.appendLine(
                    "  ${i + 1}. (${r.center.x.round()},${r.center.y.round()}) " +
                        "شدت=${(r.intensity * 100).roundToInt()}%",
                )
            }
        }

        return sb.toString()
    }

    private fun Float.round(): Int = roundToInt()

    companion object {
        private const val MIN_SHOT_DISTANCE = 15f
    }
}

internal object PuckMovementMatcher {
    fun match(
        before: List<CircleBody>,
        after: List<CircleBody>,
    ): List<FramePairAnalyzer.PuckMovement> {
        val movements = mutableListOf<FramePairAnalyzer.PuckMovement>()
        val usedAfter = BooleanArray(after.size)

        for (b in before) {
            val team = b.kind.removePrefix("puck_")
            var bestIdx = -1
            var bestDist = Float.MAX_VALUE
            val maxMatch = (b.radius * 10.0).coerceAtLeast(220.0).toFloat()

            for (i in after.indices) {
                if (usedAfter[i]) continue
                if (after[i].kind.removePrefix("puck_") != team) continue
                val d = hypot(after[i].x - b.x, after[i].y - b.y).toFloat()
                if (d < bestDist && d <= maxMatch) {
                    bestDist = d
                    bestIdx = i
                }
            }

            if (bestIdx >= 0) {
                usedAfter[bestIdx] = true
                val a = after[bestIdx]
                movements += FramePairAnalyzer.PuckMovement(
                    team = team,
                    before = PointF(b.x.toFloat(), b.y.toFloat()),
                    after = PointF(a.x.toFloat(), a.y.toFloat()),
                    displacement = PointF((a.x - b.x).toFloat(), (a.y - b.y).toFloat()),
                    distance = bestDist,
                    beforeRadius = b.radius.toFloat(),
                    afterRadius = a.radius.toFloat(),
                    matched = true,
                )
            } else {
                movements += FramePairAnalyzer.PuckMovement(
                    team = team,
                    before = PointF(b.x.toFloat(), b.y.toFloat()),
                    after = PointF(b.x.toFloat(), b.y.toFloat()),
                    displacement = PointF(0f, 0f),
                    distance = 0f,
                    beforeRadius = b.radius.toFloat(),
                    afterRadius = b.radius.toFloat(),
                    matched = false,
                )
            }
        }

        for (i in after.indices) {
            if (usedAfter[i]) continue
            val a = after[i]
            val team = a.kind.removePrefix("puck_")
            movements += FramePairAnalyzer.PuckMovement(
                team = team,
                before = PointF(a.x.toFloat(), a.y.toFloat()),
                after = PointF(a.x.toFloat(), a.y.toFloat()),
                displacement = PointF(0f, 0f),
                distance = 0f,
                beforeRadius = a.radius.toFloat(),
                afterRadius = a.radius.toFloat(),
                matched = false,
            )
        }

        return movements.sortedWith(
            compareBy<FramePairAnalyzer.PuckMovement> { it.team }.thenByDescending { it.distance },
        )
    }
}
