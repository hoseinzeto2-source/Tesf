package com.accessibility.soccerstars.physics

import org.junit.Assert.assertTrue
import org.junit.Test

class PhysicsEngineTest {
    @Test
    fun shot_moves_puck_and_can_hit_ball() {
        val bounds = FieldBounds(0.0, 0.0, 400.0, 800.0)
        val engine = PhysicsEngine(bounds, PhysicsConfig(maxShotSpeed = 20.0, maxShotPower = 100.0))

        val shooter = CircleBody(0, "puck", 200.0, 600.0, 22.0, mass = 2.0)
        val ball = CircleBody(-1, "ball", 200.0, 400.0, 12.0, mass = 1.0)

        val result = engine.simulateShot(
            listOf(shooter, ball),
            ShotInput(0, Vec2(0.0, -1.0), power = 80.0, maxPower = 100.0, maxSpeed = 20.0),
        )

        assertTrue(result.puckPath.size > 2)
        assertTrue(result.ballPath.size >= 1)
    }
}
