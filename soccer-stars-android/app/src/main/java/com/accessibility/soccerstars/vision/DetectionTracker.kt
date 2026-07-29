package com.accessibility.soccerstars.vision

import com.accessibility.soccerstars.physics.CircleBody
import kotlin.math.hypot

/**
 * Stabilizes detections across frames to reduce flicker on overlay lines.
 */
class DetectionTracker {
    private var lastPucks: List<CircleBody> = emptyList()
    private var lastBall: CircleBody? = null
    private var lastAim: AimState = AimState(false, null, null, com.accessibility.soccerstars.physics.Vec2(0.0, 0.0), 0.0)

    fun smooth(raw: FrameDetection, puckRadius: Double, ballRadius: Double): FrameDetection {
        if (raw.scene != ScenePhase.IN_MATCH) {
            lastPucks = emptyList()
            lastBall = null
            lastAim = AimState(false, null, null, com.accessibility.soccerstars.physics.Vec2(0.0, 0.0), 0.0)
            return raw
        }

        val pucks = smoothPucks(raw.pucks, puckRadius)
        val ball = smoothBall(raw.ball, ballRadius)
        val aim = if (raw.aim.active) raw.aim else decayAim()

        lastPucks = pucks
        lastBall = ball
        if (raw.aim.active) lastAim = aim

        return raw.copy(
            pucks = pucks,
            ball = ball,
            aim = aim,
            bluePuckCount = pucks.count { it.kind == "puck_blue" },
            redPuckCount = pucks.count { it.kind == "puck_red" },
        )
    }

    private fun smoothPucks(current: List<CircleBody>, radius: Double): List<CircleBody> {
        if (current.isEmpty()) return emptyList()
        if (lastPucks.isEmpty()) return current

        val used = BooleanArray(current.size)
        val merged = mutableListOf<CircleBody>()

        for (prev in lastPucks) {
            var bestIdx = -1
            var bestDist = radius * 2.5
            for (i in current.indices) {
                if (used[i]) continue
                val d = hypot(current[i].x - prev.x, current[i].y - prev.y)
                if (d < bestDist) {
                    bestDist = d
                    bestIdx = i
                }
            }
            if (bestIdx >= 0) {
                used[bestIdx] = true
                val c = current[bestIdx]
                merged += prev.copy(
                    id = c.id,
                    kind = c.kind,
                    x = prev.x * 0.35 + c.x * 0.65,
                    y = prev.y * 0.35 + c.y * 0.65,
                    radius = prev.radius * 0.5 + c.radius * 0.5,
                )
            }
        }

        for (i in current.indices) {
            if (!used[i]) merged += current[i]
        }
        return merged.take(12)
    }

    private fun smoothBall(current: CircleBody?, radius: Double): CircleBody? {
        if (current == null) return null
        val prev = lastBall
        if (prev == null) return current
        val d = hypot(current.x - prev.x, current.y - prev.y)
        return if (d < radius * 4) {
            CircleBody(
                current.id,
                current.kind,
                prev.x * 0.4 + current.x * 0.6,
                prev.y * 0.4 + current.y * 0.6,
                prev.radius * 0.5 + current.radius * 0.5,
                mass = current.mass,
            )
        } else {
            current
        }
    }

    private fun decayAim(): AimState {
        return if (lastAim.active) {
            lastAim.copy(active = false, power = lastAim.power * 0.5)
        } else {
            lastAim
        }
    }
}
