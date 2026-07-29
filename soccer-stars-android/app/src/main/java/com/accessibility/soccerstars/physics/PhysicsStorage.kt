package com.accessibility.soccerstars.physics

import android.content.Context
import android.util.JsonReader
import java.io.File
import java.io.FileReader
import java.io.InputStreamReader

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
        fun load(file: File): PhysicsConfig {
            if (!file.exists()) return PhysicsConfig()
            return parseFile(file)
        }

        fun loadFromAssets(context: Context): PhysicsConfig {
            return try {
                context.assets.open("physics.json").use { stream ->
                    parseJson(JsonReader(InputStreamReader(stream)))
                }
            } catch (_: Exception) {
                PhysicsConfig()
            }
        }

        private fun parseFile(file: File): PhysicsConfig {
            return try {
                FileReader(file).use { reader -> parseJson(JsonReader(reader)) }
            } catch (_: Exception) {
                PhysicsConfig()
            }
        }

        private fun parseJson(json: JsonReader): PhysicsConfig {
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

            return PhysicsConfig(
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
    }
}

object PhysicsStorage {
    private const val DIR_NAME = "SoccerStarsAssist"
    private const val FILE_NAME = "physics.json"

    fun configDirectory(context: Context): File {
        val base = context.getExternalFilesDir(null) ?: context.filesDir
        val dir = File(base, DIR_NAME)
        if (!dir.exists()) dir.mkdirs()
        return dir
    }

    fun physicsFile(context: Context): File = File(configDirectory(context), FILE_NAME)

    fun ensureDefault(context: Context): File {
        val target = physicsFile(context)
        if (target.exists() && target.length() > 0) return target

        target.parentFile?.mkdirs()
        return try {
            context.assets.open(FILE_NAME).use { input ->
                target.outputStream().buffered().use { output -> input.copyTo(output) }
            }
            target
        } catch (_: Exception) {
            val fallback = File(context.filesDir, FILE_NAME)
            if (!fallback.exists()) {
                context.assets.open(FILE_NAME).use { input ->
                    fallback.outputStream().buffered().use { output -> input.copyTo(output) }
                }
            }
            fallback
        }
    }

    fun loadConfig(context: Context): PhysicsConfig {
        val file = ensureDefault(context)
        val fromFile = PhysicsConfig.load(file)
        return if (file.exists() && file.length() > 0) fromFile else PhysicsConfig.loadFromAssets(context)
    }

    fun displayPath(context: Context): String = physicsFile(context).absolutePath
}
