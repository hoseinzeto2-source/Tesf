package com.accessibility.soccerstars.physics

import kotlin.math.hypot
import kotlin.math.min

data class Vec2(val x: Double, val y: Double) {
    fun length(): Double = hypot(x, y)

    fun normalized(): Vec2 {
        val len = length()
        if (len < 1e-8) return Vec2(0.0, 0.0)
        return Vec2(x / len, y / len)
    }

    operator fun plus(other: Vec2) = Vec2(x + other.x, y + other.y)
    operator fun minus(other: Vec2) = Vec2(x - other.x, y - other.y)
    operator fun times(scalar: Double) = Vec2(x * scalar, y * scalar)
}

data class FieldBounds(
    val left: Double,
    val top: Double,
    val right: Double,
    val bottom: Double,
)

data class CircleBody(
    val id: Int,
    val kind: String,
    var x: Double,
    var y: Double,
    val radius: Double,
    var vx: Double = 0.0,
    var vy: Double = 0.0,
    var mass: Double = 1.0,
    var restitution: Double = 0.92,
    val friction: Double = 0.985,
) {
    fun speed(): Double = hypot(vx, vy)
    fun copyState() = copy()
}

data class ShotInput(
    val puckId: Int,
    val direction: Vec2,
    val power: Double,
    val maxPower: Double = 140.0,
    val maxSpeed: Double = 28.0,
)

data class SimulationResult(
    val puckPath: List<Pair<Double, Double>>,
    val ballPath: List<Pair<Double, Double>>,
    val enemyPuckPaths: Map<Int, List<Pair<Double, Double>>>,
    val goalScored: Boolean,
    val finalBallPosition: Pair<Double, Double>?,
)

class PhysicsEngine(
    var bounds: FieldBounds,
    private val config: PhysicsConfig = PhysicsConfig(),
    private val stopSpeed: Double = 0.08,
    private val maxSteps: Int = 2000,
) {
    fun simulateShot(
        bodies: List<CircleBody>,
        shot: ShotInput,
        goalRect: DoubleArray? = null,
    ): SimulationResult {
        val sim = bodies.map { it.copyState() }.toMutableList()
        val shooter = sim.first { it.id == shot.puckId }
        val direction = shot.direction.normalized()
        val ratio = min(shot.power * config.powerScale / shot.maxPower, 1.0).coerceAtLeast(0.0)
        val speed = ratio * shot.maxSpeed
        shooter.vx = direction.x * speed
        shooter.vy = direction.y * speed

        val puckPath = mutableListOf(shooter.x to shooter.y)
        val enemyPaths = sim.filter { it.id != shot.puckId && it.kind.startsWith("puck") }
            .associate { it.id to mutableListOf(it.x to it.y) }
        val ball = sim.firstOrNull { it.kind == "ball" }
        val ballPath = mutableListOf<Pair<Double, Double>>()
        if (ball != null) ballPath += ball.x to ball.y

        var goalScored = false
        repeat(maxSteps) {
            val moving = sim.filter { it.speed() > stopSpeed }
            if (moving.isEmpty()) return@repeat

            moving.forEach { body ->
                body.x += body.vx
                body.y += body.vy
                resolveWall(body, config.edgeRestitution)
            }

            for (i in sim.indices) {
                for (j in i + 1 until sim.size) {
                    resolveCircle(sim[i], sim[j])
                }
            }

            moving.forEach { body ->
                val friction = if (body.kind == "ball") config.friction else config.friction
                body.vx *= friction
                body.vy *= friction
                if (body.speed() <= stopSpeed) {
                    body.vx = 0.0
                    body.vy = 0.0
                }
            }

            if (shooter.speed() > stopSpeed) puckPath += shooter.x to shooter.y
            enemyPaths.keys.forEach { id ->
                val body = sim.first { it.id == id }
                if (body.speed() > stopSpeed) {
                    enemyPaths.getValue(id) += body.x to body.y
                }
            }
            if (ball != null && ball.speed() > stopSpeed) {
                ballPath += ball.x to ball.y
                if (goalRect != null && inGoal(ball, goalRect)) {
                    goalScored = true
                    return SimulationResult(
                        puckPath,
                        ballPath,
                        enemyPaths.mapValues { it.value.toList() },
                        true,
                        ball.x to ball.y,
                    )
                }
            }
        }

        val finalBall = ball?.let { it.x to it.y }
        return SimulationResult(
            puckPath,
            ballPath,
            enemyPaths.mapValues { it.value.toList() },
            goalScored,
            finalBall,
        )
    }

    private fun inGoal(ball: CircleBody, rect: DoubleArray): Boolean {
        val (left, top, right, bottom) = rect
        return ball.x in left..right && ball.y in top..bottom
    }

    private fun resolveWall(body: CircleBody, edgeRestitution: Double) {
        if (body.x - body.radius < bounds.left) {
            body.x = bounds.left + body.radius
            body.vx = kotlin.math.abs(body.vx) * edgeRestitution
        } else if (body.x + body.radius > bounds.right) {
            body.x = bounds.right - body.radius
            body.vx = -kotlin.math.abs(body.vx) * edgeRestitution
        }

        if (body.y - body.radius < bounds.top) {
            body.y = bounds.top + body.radius
            body.vy = kotlin.math.abs(body.vy) * edgeRestitution
        } else if (body.y + body.radius > bounds.bottom) {
            body.y = bounds.bottom - body.radius
            body.vy = -kotlin.math.abs(body.vy) * edgeRestitution
        }
    }

    private fun resolveCircle(a: CircleBody, b: CircleBody) {
        val dx = b.x - a.x
        val dy = b.y - a.y
        val distance = hypot(dx, dy)
        val minDistance = a.radius + b.radius
        if (distance >= minDistance || distance < 1e-8) return

        val nx = dx / distance
        val ny = dy / distance
        val overlap = minDistance - distance
        val totalMass = a.mass + b.mass

        a.x -= nx * overlap * (b.mass / totalMass)
        a.y -= ny * overlap * (b.mass / totalMass)
        b.x += nx * overlap * (a.mass / totalMass)
        b.y += ny * overlap * (a.mass / totalMass)

        val rvx = b.vx - a.vx
        val rvy = b.vy - a.vy
        val velAlongNormal = rvx * nx + rvy * ny
        if (velAlongNormal > 0) return

        val restitution = minOf(a.restitution, b.restitution, config.restitution)
        val impulse = -(1.0 + restitution) * velAlongNormal / (1.0 / a.mass + 1.0 / b.mass)
        val impulseX = impulse * nx
        val impulseY = impulse * ny

        a.vx -= impulseX / a.mass
        a.vy -= impulseY / a.mass
        b.vx += impulseX / b.mass
        b.vy += impulseY / b.mass
    }
}
