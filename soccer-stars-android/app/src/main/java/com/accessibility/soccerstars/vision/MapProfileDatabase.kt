package com.accessibility.soccerstars.vision

import android.content.Context
import android.graphics.Color
import org.json.JSONObject
import kotlin.math.max

/**
 * Turf color profiles calibrated from 169 Soccer Stars field textures
 * in game-analysis/base/assets/unpack (see tools/calibrate_maps.py).
 */
data class TurfProfile(
    val id: String,
    val label: String,
    val hueMin: Float,
    val hueMax: Float,
    val satMin: Float,
    val valMin: Float,
)

object MapProfileDatabase {
    private var loaded = false
    private val profiles = mutableListOf<TurfProfile>()
    private val namedMaps = mutableMapOf<String, String>()

    // Puck/ball from standard APK textures (standard_blue_puck-hd, ball0-hd)
    var ballSatMax: Float = ColorCalibration.BALL_S_MAX
        private set
    var ballValMin: Float = ColorCalibration.BALL_V_MIN
        private set
    var blueHueMin: Float = ColorCalibration.BLUE_H_MIN
        private set
    var blueHueMax: Float = ColorCalibration.BLUE_H_MAX
        private set
    var blueSatMin: Float = ColorCalibration.BLUE_S_MIN
        private set
    var blueValMin: Float = ColorCalibration.BLUE_V_MIN
        private set
    var redHueMax: Float = ColorCalibration.RED_H_MAX
        private set
    var redSatMin: Float = ColorCalibration.RED_S_MIN
        private set
    var redValMin: Float = ColorCalibration.RED_V_MIN
        private set

    fun ensureLoaded(context: Context) {
        if (loaded) return
        synchronized(this) {
            if (loaded) return
            load(context)
            loaded = true
        }
    }

    fun turfProfiles(): List<TurfProfile> = profiles.toList()

    fun isFieldTurf(h: Float, s: Float, v: Float): Boolean =
        profiles.any { matchesTurf(it, h, s, v) }

    fun scoreTurfProfiles(bitmap: android.graphics.Bitmap): Map<String, Float> {
        val width = bitmap.width
        val height = bitmap.height
        val left = (width * 0.12).toInt()
        val right = (width * 0.88).toInt()
        val top = (height * 0.10).toInt()
        val bottom = (height * 0.90).toInt()
        val step = max(3, width / 90)
        val counts = profiles.associate { it.id to 0 }.toMutableMap()
        var total = 0
        var y = top
        while (y < bottom) {
            var x = left
            while (x < right) {
                total++
                val hsv = hsv(bitmap.getPixel(x, y))
                for (profile in profiles) {
                    if (matchesTurf(profile, hsv[0], hsv[1], hsv[2])) {
                        counts[profile.id] = counts.getValue(profile.id) + 1
                    }
                }
                x += step
            }
            y += step
        }
        if (total == 0) return emptyMap()
        return counts.mapValues { it.value.toFloat() / total }
    }

    fun bestMapLabel(scores: Map<String, Float>): String? {
        val best = scores.maxByOrNull { it.value } ?: return null
        if (best.value < 0.08f) return null
        return profiles.firstOrNull { it.id == best.key }?.label
    }

    private fun load(context: Context) {
        val json = context.assets.open("map_profiles.json").bufferedReader().use { it.readText() }
        val root = JSONObject(json)

        val turfArray = root.getJSONArray("turfProfiles")
        for (i in 0 until turfArray.length()) {
            val obj = turfArray.getJSONObject(i)
            val id = obj.getString("id")
            val sanitized = sanitizeProfile(id, obj)
            profiles += sanitized
        }

        if (root.has("namedMaps")) {
            val named = root.getJSONObject("namedMaps")
            named.keys().forEach { key ->
                namedMaps[key] = named.getJSONObject(key).getString("family")
            }
        }

        applyAssetCalibration(root)
    }

    private fun sanitizeProfile(id: String, obj: JSONObject): TurfProfile {
        val defaults = DEFAULT_PROFILE_BOUNDS[id] ?: ProfileBounds(0f, 360f, 0.08f, 0.12f)
        return TurfProfile(
            id = id,
            label = obj.getString("label"),
            hueMin = max(defaults.hueMin, obj.getDouble("hueMin").toFloat()),
            hueMax = minOf(defaults.hueMax, obj.getDouble("hueMax").toFloat().let {
                if (it < defaults.hueMin) defaults.hueMax else it
            }),
            satMin = max(defaults.satMin, obj.getDouble("satMin").toFloat()),
            valMin = max(defaults.valMin, obj.getDouble("valMin").toFloat()),
        )
    }

    private fun applyAssetCalibration(root: JSONObject) {
        if (!root.has("pucks")) return
        val pucks = root.getJSONArray("pucks")
        for (i in 0 until pucks.length()) {
            val p = pucks.getJSONObject(i)
            if (!p.getString("name").contains("blue")) continue
            blueHueMin = p.getJSONObject("hue").getDouble("p10").toFloat().coerceIn(170f, 220f)
            blueHueMax = p.getJSONObject("hue").getDouble("p90").toFloat().coerceIn(220f, 250f)
            blueSatMin = p.getJSONObject("sat").getDouble("p10").toFloat().coerceAtLeast(0.30f)
            blueValMin = p.getJSONObject("val").getDouble("p10").toFloat().coerceAtLeast(0.28f)
            break
        }
        if (!root.has("balls")) return
        val balls = root.getJSONArray("balls")
        if (balls.length() > 0) {
            val b = balls.getJSONObject(0)
            ballSatMax = b.getJSONObject("sat").getDouble("p90").toFloat().coerceAtMost(0.35f)
            ballValMin = b.getJSONObject("val").getDouble("p10").toFloat().coerceAtLeast(0.65f)
        }
    }

    private fun matchesTurf(profile: TurfProfile, h: Float, s: Float, v: Float): Boolean {
        if (s < profile.satMin || v < profile.valMin) return false
        return if (profile.hueMin <= profile.hueMax) {
            h in profile.hueMin..profile.hueMax
        } else {
            h >= profile.hueMin || h <= profile.hueMax
        }
    }

    private fun hsv(color: Int): FloatArray {
        val hsv = FloatArray(3)
        Color.colorToHSV(color, hsv)
        return hsv
    }

    private data class ProfileBounds(val hueMin: Float, val hueMax: Float, val satMin: Float, val valMin: Float)

    private val DEFAULT_PROFILE_BOUNDS = mapOf(
        "green" to ProfileBounds(58f, 140f, 0.20f, 0.15f),
        "yellow_brown" to ProfileBounds(20f, 78f, 0.10f, 0.18f),
        "gold" to ProfileBounds(35f, 100f, 0.08f, 0.12f),
        "ice" to ProfileBounds(165f, 225f, 0.05f, 0.35f),
        "cyber" to ProfileBounds(110f, 240f, 0.15f, 0.12f),
        "street" to ProfileBounds(12f, 58f, 0.08f, 0.15f),
        "arena" to ProfileBounds(20f, 250f, 0.15f, 0.22f),
    )
}
