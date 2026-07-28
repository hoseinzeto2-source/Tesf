package com.accessibility.soccerstars.vision

import android.content.Context
import android.graphics.Bitmap
import android.graphics.Color

/**
 * Field/turf color matching using APK texture calibration.
 */
class FieldColorMatcher(context: Context) {
    init {
        MapProfileDatabase.ensureLoaded(context.applicationContext)
    }

    fun isFieldTurf(color: Int): Boolean {
        val hsv = hsv(color)
        return MapProfileDatabase.isFieldTurf(hsv[0], hsv[1], hsv[2])
    }

    fun isFieldLine(color: Int): Boolean {
        val hsv = hsv(color)
        return hsv[1] <= 0.18f && hsv[2] >= 0.82f
    }

    fun isBallColor(color: Int): Boolean {
        val hsv = hsv(color)
        return hsv[1] <= MapProfileDatabase.ballSatMax && hsv[2] >= MapProfileDatabase.ballValMin
    }

    fun isBlueTeam(color: Int): Boolean {
        val hsv = hsv(color)
        return hsv[0] in MapProfileDatabase.blueHueMin..MapProfileDatabase.blueHueMax &&
            hsv[1] >= MapProfileDatabase.blueSatMin &&
            hsv[2] >= MapProfileDatabase.blueValMin
    }

    fun isRedTeam(color: Int): Boolean {
        val hsv = hsv(color)
        return (hsv[0] <= MapProfileDatabase.redHueMax || hsv[0] >= ColorCalibration.RED_H_WRAP_MIN) &&
            hsv[1] >= MapProfileDatabase.redSatMin &&
            hsv[2] >= MapProfileDatabase.redValMin
    }

    fun detectMapFamily(bitmap: Bitmap): String? =
        MapProfileDatabase.bestMapLabel(MapProfileDatabase.scoreTurfProfiles(bitmap))

    private fun hsv(color: Int): FloatArray {
        val hsv = FloatArray(3)
        Color.colorToHSV(color, hsv)
        return hsv
    }
}
