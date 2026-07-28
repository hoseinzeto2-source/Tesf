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
        return hsv[0] in PuckValidator.BLUE_H_MIN..PuckValidator.BLUE_H_MAX &&
            hsv[1] >= PuckValidator.BLUE_S_MIN &&
            hsv[2] >= PuckValidator.BLUE_V_MIN
    }

    fun isRedTeam(color: Int): Boolean {
        val hsv = hsv(color)
        return (hsv[0] <= PuckValidator.RED_H_MAX || hsv[0] >= PuckValidator.RED_H_WRAP) &&
            hsv[1] >= PuckValidator.RED_S_MIN &&
            hsv[2] >= PuckValidator.RED_V_MIN
    }

    fun detectMapFamily(bitmap: Bitmap): String? =
        MapProfileDatabase.bestMapLabel(MapProfileDatabase.scoreTurfProfiles(bitmap))

    private fun hsv(color: Int): FloatArray {
        val hsv = FloatArray(3)
        Color.colorToHSV(color, hsv)
        return hsv
    }
}
