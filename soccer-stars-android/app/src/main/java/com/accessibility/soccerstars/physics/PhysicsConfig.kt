package com.accessibility.soccerstars.physics

import android.util.JsonReader
import java.io.File
import java.io.FileReader

data class PhysicsConfig(
    val maxShotPower: Double = 140.0,
    val maxShotSpeed: Double = 28.0,
    val puckMass: Double = 2.0,
    val ballMass: Double = 1.0,
    val friction: Double = 0.985,
    val restitution: Double = 0.92,
    val edgeRestitution: Double = 0.92,
    val powerScale: Double = 1.0,
) {
    companion object {
        const val DEFAULT_PATH = "/sdcard/SoccerStarsAssist/physics.json"

        fun load(path: String = DEFAULT_PATH): PhysicsConfig {
            val candidates = listOf(path, DEFAULT_PATH)
            for (candidate in candidates) {
                val file = File(candidate)
                if (file.exists()) return parseFile(file)
            }
            return PhysicsConfig()
        }

        private fun parseFile(file: File): PhysicsConfig {
            return try {
                FileReader(file).use { reader ->
                    val json = JsonReader(reader)
                    var maxShotPower = 140.0
                    var maxShotSpeed = 28.0
                    var puckMass = 2.0
                    var ballMass = 1.0
                    var friction = 0.985
                    var restitution = 0.92
                    var edgeRestitution = 0.92
                    var powerScale = 1.0

                    json.beginObject()
                    while (json.hasNext()) {
                        when (json.nextName()) {
                            "maxShotPower" -> maxShotPower = json.nextDouble()
                            "maxShotSpeed" -> maxShotSpeed = json.nextDouble()
                            "puckMass" -> puckMass = json.nextDouble()
                            "ballMass" -> ballMass = json.nextDouble()
                            "friction" -> friction = json.nextDouble()
                            "restitution" -> restitution = json.nextDouble()
                            "edgeRestitution" -> edgeRestitution = json.nextDouble()
                            "powerScale" -> powerScale = json.nextDouble()
                            else -> json.skipValue()
                        }
                    }
                    json.endObject()

                    PhysicsConfig(
                        maxShotPower = maxShotPower,
                        maxShotSpeed = maxShotSpeed,
                        puckMass = puckMass,
                        ballMass = ballMass,
                        friction = friction,
                        restitution = restitution,
                        edgeRestitution = edgeRestitution,
                        powerScale = powerScale,
                    )
                }
            } catch (_: Exception) {
                PhysicsConfig()
            }
        }
    }
}
