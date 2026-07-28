package com.accessibility.soccerstars.vision

import android.graphics.Bitmap
import android.graphics.Color
import com.accessibility.soccerstars.physics.FieldBounds
import kotlin.math.max
import kotlin.math.min

data class FieldScan(
    val bounds: FieldBounds?,
    val scene: ScenePhase,
    val centerGreenRatio: Float,
    val fieldAreaRatio: Float,
)

class FieldDetector {
    fun scan(bitmap: Bitmap): FieldScan {
        val width = bitmap.width
        val height = bitmap.height
        val centerGreen = measureCenterGreen(bitmap, width, height)
        val bounds = detectPlayField(bitmap, width, height)
        val areaRatio = bounds?.let {
            ((it.right - it.left) * (it.bottom - it.top) / (width * height)).toFloat()
        } ?: 0f

        val scene = classifyScene(centerGreen, bounds, areaRatio, width, height)
        val finalBounds = if (scene == ScenePhase.IN_MATCH) bounds else null
        return FieldScan(finalBounds, scene, centerGreen, areaRatio)
    }

    fun playArea(bounds: FieldBounds): FieldBounds {
        val h = bounds.bottom - bounds.top
        val w = bounds.right - bounds.left
        return FieldBounds(
            left = bounds.left + w * 0.04,
            top = bounds.top + h * 0.10,
            right = bounds.right - w * 0.04,
            bottom = bounds.bottom - h * 0.08,
        )
    }

    private fun classifyScene(
        centerGreen: Float,
        bounds: FieldBounds?,
        areaRatio: Float,
        width: Int,
        height: Int,
    ): ScenePhase {
        if (bounds == null) {
            return if (centerGreen < ColorCalibration.MENU_CENTER_GREEN_MAX) {
                ScenePhase.MENU_OR_HOME
            } else {
                ScenePhase.UNKNOWN
            }
        }

        val fieldH = (bounds.bottom - bounds.top) / height
        val fieldW = bounds.right - bounds.left
        val fieldHpx = bounds.bottom - bounds.top
        val aspect = fieldW / fieldHpx

        val geometryOk = fieldH >= ColorCalibration.FIELD_MIN_HEIGHT_RATIO &&
            areaRatio >= ColorCalibration.FIELD_MIN_AREA_RATIO &&
            aspect in ColorCalibration.FIELD_ASPECT_MIN..ColorCalibration.FIELD_ASPECT_MAX

        return when {
            centerGreen >= ColorCalibration.MATCH_CENTER_GREEN_MIN && geometryOk -> ScenePhase.IN_MATCH
            centerGreen < ColorCalibration.MENU_CENTER_GREEN_MAX -> ScenePhase.MENU_OR_HOME
            geometryOk && centerGreen >= 0.18f -> ScenePhase.IN_MATCH
            centerGreen >= 0.22f && areaRatio >= ColorCalibration.FIELD_MIN_AREA_RATIO -> ScenePhase.IN_MATCH
            else -> ScenePhase.UNKNOWN
        }
    }

    private fun measureCenterGreen(bitmap: Bitmap, width: Int, height: Int): Float {
        val left = (width * 0.15).toInt()
        val right = (width * 0.85).toInt()
        val top = (height * 0.12).toInt()
        val bottom = (height * 0.88).toInt()
        val step = max(3, width / 100)
        var green = 0
        var total = 0
        var y = top
        while (y < bottom) {
            var x = left
            while (x < right) {
                total++
                if (isFieldGreen(bitmap.getPixel(x, y))) green++
                x += step
            }
            y += step
        }
        return if (total == 0) 0f else green.toFloat() / total
    }

    private fun detectPlayField(bitmap: Bitmap, width: Int, height: Int): FieldBounds? {
        val stepY = max(2, height / 80)
        val stepX = max(2, width / 60)
        val marginX = (width * 0.08).toInt()

        var top = -1
        var bottom = -1
        var y = 0
        while (y < height) {
            var green = 0
            var total = 0
            var x = marginX
            while (x < width - marginX) {
                total++
                if (isFieldGreen(bitmap.getPixel(x, y))) green++
                x += stepX
            }
            val ratio = if (total == 0) 0f else green.toFloat() / total
            if (ratio > 0.48f) {
                if (top < 0) top = y
                bottom = y
            }
            y += stepY
        }

        if (top < 0 || bottom <= top) return null

        var left = width
        var right = 0
        var x = 0
        while (x < width) {
            var green = 0
            var total = 0
            var ry = top
            while (ry <= bottom) {
                total++
                if (isFieldGreen(bitmap.getPixel(x, ry))) green++
                ry += stepY
            }
            val ratio = if (total == 0) 0f else green.toFloat() / total
            if (ratio > 0.38f) {
                left = min(left, x)
                right = max(right, x)
            }
            x += stepX
        }

        if (right <= left) return null

        val padX = (right - left) * 0.015
        val padY = (bottom - top) * 0.012
        return FieldBounds(
            left = left + padX,
            top = top + padY,
            right = right - padX,
            bottom = bottom - padY,
        )
    }

    private fun isFieldGreen(color: Int): Boolean {
        val hsv = FloatArray(3)
        Color.colorToHSV(color, hsv)
        return hsv[0] in ColorCalibration.FIELD_H_MIN..ColorCalibration.FIELD_H_MAX &&
            hsv[1] >= ColorCalibration.FIELD_S_MIN &&
            hsv[2] >= ColorCalibration.FIELD_V_MIN
    }
}
