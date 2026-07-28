package com.accessibility.soccerstars.vision

import com.accessibility.soccerstars.physics.CircleBody
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test

class FramePairAnalyzerTest {

    @Test
    fun matchPucks_pairsByTeamAndProximity() {
        val before = listOf(
            puck("blue", 100.0, 200.0),
            puck("red", 300.0, 200.0),
        )
        val after = listOf(
            puck("blue", 180.0, 240.0),
            puck("red", 302.0, 205.0),
        )

        val movements = PuckMovementMatcher.match(before, after)
        assertEquals(2, movements.size)

        val blue = movements.first { it.team == "blue" }
        assertTrue(blue.matched)
        assertTrue(blue.distance > 50f)

        val red = movements.first { it.team == "red" }
        assertTrue(red.matched)
        assertTrue(red.distance < 20f)
    }

    @Test
    fun matchPucks_identifiesShooterAsLargestMovement() {
        val before = listOf(
            puck("blue", 100.0, 200.0),
            puck("blue", 400.0, 200.0),
        )
        val after = listOf(
            puck("blue", 250.0, 260.0),
            puck("blue", 402.0, 201.0),
        )

        val movements = PuckMovementMatcher.match(before, after)
        val shooter = movements.filter { it.matched }.maxByOrNull { it.distance }
        assertNotNull(shooter)
        assertTrue(shooter!!.distance > 100f)
    }

    private fun puck(team: String, x: Double, y: Double) = CircleBody(
        id = 0,
        kind = "puck_$team",
        x = x,
        y = y,
        radius = 22.0,
    )
}
