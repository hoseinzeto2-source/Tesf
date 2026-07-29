package com.accessibility.soccerstars.vision

import android.content.Context
import android.graphics.Bitmap
import android.graphics.Color
import com.accessibility.soccerstars.physics.CircleBody
import com.accessibility.soccerstars.physics.FieldBounds
import com.accessibility.soccerstars.physics.Vec2
import kotlin.math.PI
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
    val scene: ScenePhase = ScenePhase.UNKNOWN,
    val centerGreenRatio: Float = 0f,
    val bluePuckCount: Int = 0,
    val redPuckCount: Int = 0,
    val analysisNotes: List<String> = emptyList(),
    val mapFamily: String? = null,
    val postShotMotions: List<MotionTrailDetector.MotionEstimate> = emptyList(),
    val isPostShot: Boolean = false,
)

class GameDetector(
    context: Context,
    private val maxShotPower: Double = 140.0,
) {
    private val colorMatcher = FieldColorMatcher(context)
    private val puckValidator = PuckValidator(colorMatcher)
    private val motionDetector = MotionTrailDetector(puckValidator)
    private val tracker = DetectionTracker()
    private val fieldDetector = FieldDetector(colorMatcher)

    /**
     * Lab/offline analysis: always scan the play area and skip temporal smoothing.
     */
    fun analyzeGameplay(bitmap: Bitmap): FrameDetection {
        val width = bitmap.width
        val height = bitmap.height
        val scan = fieldDetector.scan(bitmap)
        val bounds = scan.bounds ?: fieldDetector.fullScreenBounds(width, height)
        val playArea = fieldDetector.playArea(bounds)
        val scale = ((bounds.right - bounds.left) / 360.0).coerceIn(0.55, 1.8)
        val puckRadius = 22.0 * scale
        val ballRadius = 12.0 * scale

        val ball = detectBall(bitmap, playArea, ballRadius)
        val pucks = detectPucks(bitmap, playArea, ball, puckRadius, strictRed = isBrownField(scan, bitmap))
        val aim = detectAimLine(bitmap, playArea, pucks, maxShotPower)
        val motions = if (!aim.active && pucks.size >= 4) {
            motionDetector.detect(bitmap, pucks)
        } else {
            emptyList()
        }
        val isPostShot = !aim.active && motions.isNotEmpty()
        val blueCount = pucks.count { it.kind == "puck_blue" }
        val redCount = pucks.count { it.kind == "puck_red" }
        val mapFamily = colorMatcher.detectMapFamily(bitmap)
        val scene = inferGameplayScene(pucks, ball, scan) ?: ScenePhase.IN_MATCH

        return FrameDetection(
            bounds = bounds,
            ball = ball,
            pucks = pucks,
            aim = aim,
            confidence = scoreConfidence(scan, ball, pucks, aim),
            scene = scene,
            centerGreenRatio = scan.centerTurfRatio,
            bluePuckCount = blueCount,
            redPuckCount = redCount,
            analysisNotes = buildAnalysisNotes(
                scan, pucks, ball, aim, blueCount, redCount, mapFamily, motions, isPostShot,
            ),
            mapFamily = mapFamily,
            postShotMotions = motions,
            isPostShot = isPostShot,
        )
    }

    fun detect(bitmap: Bitmap): FrameDetection {
        val width = bitmap.width
        val height = bitmap.height
        val scan = fieldDetector.scan(bitmap)

        var bounds = scan.bounds ?: fallbackBounds(width, height, scan)
        var scene = resolveScene(scan, bounds)

        if (scene != ScenePhase.IN_MATCH || bounds == null) {
            val fullBounds = fieldDetector.fullScreenBounds(width, height)
            val playArea = fieldDetector.playArea(fullBounds)
            val scale = ((fullBounds.right - fullBounds.left) / 360.0).coerceIn(0.55, 1.8)
            val puckRadius = 22.0 * scale
            val ballRadius = 12.0 * scale
            val ball = detectBall(bitmap, playArea, ballRadius)
            val pucks = detectPucks(bitmap, playArea, ball, puckRadius, isBrownField(scan, bitmap))
            val gameplay = inferGameplayScene(pucks, ball, scan)
            if (gameplay != null) {
                bounds = fullBounds
                scene = gameplay
            } else {
                return tracker.smooth(
                    FrameDetection(
                        bounds = null,
                        ball = null,
                        pucks = emptyList(),
                        aim = AimState(false, null, null, Vec2(0.0, 0.0), 0.0),
                        confidence = scoreSceneConfidence(scan),
                        scene = scene,
                        centerGreenRatio = scan.centerTurfRatio,
                    ),
                    puckRadius = 20.0,
                    ballRadius = 12.0,
                )
            }
        }

        val playArea = fieldDetector.playArea(bounds!!)
        val scale = ((bounds.right - bounds.left) / 360.0).coerceIn(0.55, 1.8)
        val puckRadius = 22.0 * scale
        val ballRadius = 12.0 * scale

        val ball = detectBall(bitmap, playArea, ballRadius)
        val strictRed = isBrownField(scan, bitmap)
        val pucks = detectPucks(bitmap, playArea, ball, puckRadius, strictRed)
        val aim = detectAimLine(bitmap, playArea, pucks, maxShotPower)

        val motions = if (!aim.active && pucks.size >= 4) motionDetector.detect(bitmap, pucks) else emptyList()
        val isPostShot = !aim.active && motions.isNotEmpty()

        val blueCount = pucks.count { it.kind == "puck_blue" }
        val redCount = pucks.count { it.kind == "puck_red" }
        val mapFamily = colorMatcher.detectMapFamily(bitmap)
        val notes = buildAnalysisNotes(scan, pucks, ball, aim, blueCount, redCount, mapFamily, motions, isPostShot)

        val raw = FrameDetection(
            bounds = bounds,
            ball = ball,
            pucks = pucks,
            aim = aim,
            confidence = scoreConfidence(scan, ball, pucks, aim),
            scene = inferGameplayScene(pucks, ball, scan) ?: scene,
            centerGreenRatio = scan.centerTurfRatio,
            bluePuckCount = blueCount,
            redPuckCount = redCount,
            analysisNotes = notes,
            mapFamily = mapFamily,
            postShotMotions = motions,
            isPostShot = isPostShot,
        )
        return tracker.smooth(raw, puckRadius, ballRadius)
    }

    fun nearestPuckToAim(detection: FrameDetection): CircleBody? {
        if (!detection.aim.active || detection.pucks.isEmpty()) return null
        val (sx, sy) = detection.aim.start ?: return null
        return detection.pucks.minByOrNull { hypot(it.x - sx, it.y - sy) }
    }

    private fun resolveScene(scan: FieldScan, bounds: FieldBounds?): ScenePhase = when {
        scan.scene == ScenePhase.IN_MATCH -> ScenePhase.IN_MATCH
        bounds != null && scan.centerTurfRatio >= ColorCalibration.MATCH_CENTER_FIELD_MIN -> ScenePhase.IN_MATCH
        bounds != null && scan.whiteLineRatio >= 0.01f -> ScenePhase.IN_MATCH
        scan.scene == ScenePhase.MENU_OR_HOME -> ScenePhase.MENU_OR_HOME
        else -> scan.scene
    }

    private fun inferGameplayScene(
        pucks: List<CircleBody>,
        ball: CircleBody?,
        scan: FieldScan,
    ): ScenePhase? {
        if (pucks.size >= 4 || (pucks.size >= 2 && ball != null)) return ScenePhase.IN_MATCH
        if (pucks.size >= 2 && scan.centerTurfRatio >= 0.10f) return ScenePhase.IN_MATCH
        if (ball != null && scan.whiteLineRatio >= 0.008f) return ScenePhase.IN_MATCH
        return null
    }

    private fun fallbackBounds(width: Int, height: Int, scan: FieldScan): FieldBounds? {
        if (scan.centerTurfRatio < ColorCalibration.MATCH_CENTER_FIELD_MIN &&
            scan.whiteLineRatio < 0.008f
        ) {
            return null
        }
        return fieldDetector.fullScreenBounds(width, height)
    }

    private fun buildAnalysisNotes(
        scan: FieldScan,
        pucks: List<CircleBody>,
        ball: CircleBody?,
        aim: AimState,
        blueCount: Int,
        redCount: Int,
        mapFamily: String?,
        motions: List<MotionTrailDetector.MotionEstimate>,
        isPostShot: Boolean,
    ): List<String> = buildList {
        mapFamily?.let { add("نوع مپ: $it") }
        add("زمین: ${(scan.centerTurfRatio * 100).toInt()}% · خطوط سفید: ${(scan.whiteLineRatio * 1000).toInt()}/1000")
        add("مهره آبی: $blueCount · مهره قرمز: $redCount")
        if (ball != null) add("توپ: (${ball.x.toInt()}, ${ball.y.toInt()})")
        when {
            aim.active -> add("فلش شلیک: قدرت ${aim.power.toInt()}")
            isPostShot -> {
                add("بعد از شلیک — ${motions.size} مهره در حرکت")
                motions.take(3).forEach { m ->
                    add("  ${m.team}: سرعت ${m.speed.toInt()} · طول مسیر ${m.trailLength.toInt()}px")
                }
            }
            else -> add("فلش شلیک: پیدا نشد — مهره را بکشید یا عکس بعد از شلیک بگذارید")
        }
    }

    private fun scoreSceneConfidence(scan: FieldScan): Float = when (scan.scene) {
        ScenePhase.IN_MATCH -> 0.5f + scan.centerTurfRatio.coerceAtMost(0.5f)
        ScenePhase.MENU_OR_HOME -> 0.05f
        ScenePhase.UNKNOWN -> 0.1f
    }

    private fun scoreConfidence(
        scan: FieldScan,
        ball: CircleBody?,
        pucks: List<CircleBody>,
        aim: AimState,
    ): Float {
        var score = 0.30f
        if (ball != null) score += 0.22f
        when (pucks.size) {
            in 4..12 -> score += 0.28f
            in 2..12 -> score += 0.14f
        }
        if (aim.active) score += 0.20f
        score += scan.centerTurfRatio.coerceAtMost(0.15f)
        score += scan.whiteLineRatio.coerceAtMost(0.08f) * 5f
        return score.coerceIn(0f, 1f)
    }

    private fun detectBall(bitmap: Bitmap, area: FieldBounds, radius: Double): CircleBody? {
        val candidates = mutableListOf<CircleCandidate>()
        val step = max(3, (radius / 3).toInt())
        var y = area.top.toInt()
        while (y < area.bottom) {
            var x = area.left.toInt()
            while (x < area.right) {
                if (isBallColor(bitmap.getPixel(x, y))) {
                    val refined = refineCircle(bitmap, x, y, radius * 0.60, radius * 1.40, ::isBallColor)
                    if (refined != null && isBallPattern(bitmap, refined.x, refined.y, refined.radius)) {
                        candidates += refined
                    }
                }
                x += step
            }
            y += step
        }
        return pickBestCircle(candidates, -1, "ball", 1.0)?.let {
            CircleBody(-1, "ball", it.x, it.y, it.radius, mass = 1.0, restitution = 0.92)
        }
    }

    private fun isBrownField(scan: FieldScan, bitmap: Bitmap): Boolean {
        val family = colorMatcher.detectMapFamily(bitmap)
        if (family == "street" || family == "yellow_brown") return true
        return scan.centerTurfRatio >= 0.08f && scan.whiteLineRatio < 0.02f
    }

    private fun detectPucks(
        bitmap: Bitmap,
        area: FieldBounds,
        ball: CircleBody?,
        radius: Double,
        strictRed: Boolean = false,
    ): List<CircleBody> {
        val candidates = mutableListOf<PuckValidator.ScoredPuck>()
        val step = max(4, (radius / 2.0).toInt())
        var y = area.top.toInt()
        while (y < area.bottom) {
            var x = area.left.toInt()
            while (x < area.right) {
                val pixel = bitmap.getPixel(x, y)
                val team = puckValidator.teamAt(bitmap, pixel, x, y)
                if (team != null) {
                    val refined = refineCircle(bitmap, x, y, radius * 0.68, radius * 1.32) { c ->
                        puckValidator.teamAt(bitmap, c, x, y) == team
                    }
                    if (refined != null) {
                        val score = puckValidator.scoreCandidate(bitmap, refined.x, refined.y, refined.radius, team)
                        val minScore = if (strictRed && team == "red") 58 else 42
                        if (score >= minScore) {
                            candidates += PuckValidator.ScoredPuck(
                                refined.x, refined.y, refined.radius, team, score + refined.score,
                            )
                        }
                    }
                }
                x += step
            }
            y += step
        }

        val merged = mergeScored(candidates, radius * 1.9)
        return puckValidator.filterAndCap(merged)
            .mapIndexed { index, c ->
                CircleBody(index, "puck_${c.team}", c.x, c.y, c.radius, mass = 2.0)
            }
            .filter { puck ->
                ball == null || hypot(puck.x - ball.x, puck.y - ball.y) >= radius + ball.radius * 0.8
            }
    }

    private fun mergeScored(
        candidates: List<PuckValidator.ScoredPuck>,
        minDistance: Double,
    ): List<PuckValidator.ScoredPuck> {
        val sorted = candidates.sortedByDescending { it.score }
        val kept = mutableListOf<PuckValidator.ScoredPuck>()
        for (c in sorted) {
            val tooClose = kept.any { hypot(it.x - c.x, it.y - c.y) < minDistance }
            if (!tooClose) kept += c
        }
        return kept
    }

    private fun detectAimLine(
        bitmap: Bitmap,
        area: FieldBounds,
        pucks: List<CircleBody>,
        maxPower: Double,
    ): AimState {
        if (pucks.isEmpty()) return AimState(false, null, null, Vec2(0.0, 0.0), 0.0)

        val points = mutableListOf<Pair<Int, Int>>()
        val step = max(2, bitmap.width / 280)
        val y0 = area.top.toInt()
        val y1 = area.bottom.toInt()
        val x0 = area.left.toInt()
        val x1 = area.right.toInt()
        for (y in y0 until y1 step step) {
            for (x in x0 until x1 step step) {
                if (isAimGuideLine(bitmap.getPixel(x, y))) points += x to y
            }
        }
        if (points.size < 3) return AimState(false, null, null, Vec2(0.0, 0.0), 0.0)

        var bestLength = 0.0
        var bestStart: Pair<Int, Int>? = null
        var bestEnd: Pair<Int, Int>? = null

        for (puck in pucks) {
            val near = points.filter { hypot(it.first - puck.x, it.second - puck.y) < puck.radius * 2.5 }
            if (near.size < 3) continue

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
                bestStart = (centroidX + dirX * minProj).toInt() to (centroidY + dirY * minProj).toInt()
                bestEnd = (centroidX + dirX * maxProj).toInt() to (centroidY + dirY * maxProj).toInt()
            }
        }

        if (bestStart == null || bestEnd == null || bestLength < 8) {
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
            power = min(bestLength * 1.15, maxPower),
        )
    }

    private fun isBallPattern(bitmap: Bitmap, cx: Double, cy: Double, radius: Double): Boolean {
        val whiteHits = countDisk(bitmap, cx.toInt(), cy.toInt(), radius * 0.55, ::isBallColor)
        val darkHits = countDisk(bitmap, cx.toInt(), cy.toInt(), radius * 0.55) { c ->
            val hsv = hsv(c)
            hsv[2] < 0.45f
        }
        return whiteHits >= 6 || (whiteHits >= 4 && darkHits >= 2)
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
            val innerHits = countDisk(bitmap, seedX, seedY, r * 0.42, predicate)
            val score = hits * 2 + innerHits
            if (total > 0 && hits >= steps / 4) {
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

    private fun isBallColor(color: Int): Boolean = colorMatcher.isBallColor(color)

    private fun isYellowLine(color: Int): Boolean {
        val hsv = hsv(color)
        return hsv[0] in ColorCalibration.YELLOW_H_MIN..ColorCalibration.YELLOW_H_MAX &&
            hsv[1] >= ColorCalibration.YELLOW_S_MIN &&
            hsv[2] >= ColorCalibration.YELLOW_V_MIN
    }

    private fun isOrangeLine(color: Int): Boolean {
        val hsv = hsv(color)
        return hsv[0] in ColorCalibration.ORANGE_H_MIN..ColorCalibration.ORANGE_H_MAX &&
            hsv[1] >= ColorCalibration.ORANGE_S_MIN &&
            hsv[2] >= ColorCalibration.ORANGE_V_MIN
    }

    private fun isAimGuideLine(color: Int): Boolean = isYellowLine(color) || isOrangeLine(color)

    private fun hsv(color: Int): FloatArray {
        val hsv = FloatArray(3)
        Color.colorToHSV(color, hsv)
        return hsv
    }
}
