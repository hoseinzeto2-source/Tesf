package com.accessibility.soccerstars.vision

import android.graphics.Bitmap
import com.accessibility.soccerstars.physics.FieldBounds
import kotlin.math.max
import kotlin.math.min

data class FieldScan(
    val bounds: FieldBounds?,
    val scene: ScenePhase,
    val centerGreenRatio: Float,
    val fieldAreaRatio: Float,
    val centerTurfRatio: Float = centerGreenRatio,
    val whiteLineRatio: Float = 0f,
)

class FieldDetector(
    private val colorMatcher: FieldColorMatcher,
) {
    fun scan(bitmap: Bitmap): FieldScan {
        val width = bitmap.width
        val height = bitmap.height
        val centerTurf = measureCenterTurf(bitmap, width, height)
        val whiteLines = measureWhiteLines(bitmap, width, height)
        val bounds = detectPlayField(bitmap, width, height)
        val areaRatio = bounds?.let {
            ((it.right - it.left) * (it.bottom - it.top) / (width * height)).toFloat()
        } ?: 0f

        val scene = classifyScene(centerTurf, whiteLines, bounds, areaRatio, height)
        val finalBounds = if (scene == ScenePhase.IN_MATCH) bounds else null
        return FieldScan(
            bounds = finalBounds,
            scene = scene,
            centerGreenRatio = centerTurf,
            fieldAreaRatio = areaRatio,
            centerTurfRatio = centerTurf,
            whiteLineRatio = whiteLines,
        )
    }

    fun playArea(bounds: FieldBounds): FieldBounds {
        val h = bounds.bottom - bounds.top
        val w = bounds.right - bounds.left
        return FieldBounds(
            left = bounds.left + w * 0.03,
            top = bounds.top + h * 0.08,
            right = bounds.right - w * 0.03,
            bottom = bounds.bottom - h * 0.06,
        )
    }

    fun fullScreenBounds(width: Int, height: Int): FieldBounds = FieldBounds(
        left = width * 0.02,
        top = height * 0.06,
        right = width * 0.98,
        bottom = height * 0.96,
    )

    private fun classifyScene(
        centerTurf: Float,
        whiteLineRatio: Float,
        bounds: FieldBounds?,
        areaRatio: Float,
        height: Int,
    ): ScenePhase {
        if (bounds == null) {
            return if (centerTurf < ColorCalibration.MENU_CENTER_FIELD_MAX && whiteLineRatio < 0.01f) {
                ScenePhase.MENU_OR_HOME
            } else if (centerTurf >= ColorCalibration.MATCH_CENTER_FIELD_MIN || whiteLineRatio >= 0.012f) {
                ScenePhase.IN_MATCH
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
            geometryOk && (centerTurf >= ColorCalibration.MATCH_CENTER_FIELD_MIN || whiteLineRatio >= 0.01f) ->
                ScenePhase.IN_MATCH
            centerTurf < ColorCalibration.MENU_CENTER_FIELD_MAX && whiteLineRatio < 0.008f ->
                ScenePhase.MENU_OR_HOME
            geometryOk -> ScenePhase.IN_MATCH
            centerTurf >= 0.12f && areaRatio >= ColorCalibration.FIELD_MIN_AREA_RATIO ->
                ScenePhase.IN_MATCH
            else -> ScenePhase.UNKNOWN
        }
    }

    private fun measureCenterTurf(bitmap: Bitmap, width: Int, height: Int): Float {
        val left = (width * 0.12).toInt()
        val right = (width * 0.88).toInt()
        val top = (height * 0.10).toInt()
        val bottom = (height * 0.90).toInt()
        val step = max(3, width / 90)
        var turf = 0
        var total = 0
        var y = top
        while (y < bottom) {
            var x = left
            while (x < right) {
                total++
                if (colorMatcher.isFieldTurf(bitmap.getPixel(x, y))) turf++
                x += step
            }
            y += step
        }
        return if (total == 0) 0f else turf.toFloat() / total
    }

    private fun measureWhiteLines(bitmap: Bitmap, width: Int, height: Int): Float {
        val left = (width * 0.10).toInt()
        val right = (width * 0.90).toInt()
        val top = (height * 0.12).toInt()
        val bottom = (height * 0.88).toInt()
        val step = max(4, width / 70)
        var white = 0
        var total = 0
        var y = top
        while (y < bottom) {
            var x = left
            while (x < right) {
                total++
                if (colorMatcher.isFieldLine(bitmap.getPixel(x, y))) white++
                x += step
            }
            y += step
        }
        return if (total == 0) 0f else white.toFloat() / total
    }

    private fun detectPlayField(bitmap: Bitmap, width: Int, height: Int): FieldBounds? {
        val stepY = max(2, height / 70)
        val stepX = max(2, width / 50)
        val marginX = (width * 0.05).toInt()

        var top = -1
        var bottom = -1
        var y = 0
        while (y < height) {
            var turf = 0
            var total = 0
            var x = marginX
            while (x < width - marginX) {
                total++
                if (colorMatcher.isFieldTurf(bitmap.getPixel(x, y))) turf++
                x += stepX
            }
            val ratio = if (total == 0) 0f else turf.toFloat() / total
            if (ratio > 0.34f) {
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
            var turf = 0
            var total = 0
            var ry = top
            while (ry <= bottom) {
                total++
                if (colorMatcher.isFieldTurf(bitmap.getPixel(x, ry))) turf++
                ry += stepY
            }
            val ratio = if (total == 0) 0f else turf.toFloat() / total
            if (ratio > 0.28f) {
                left = min(left, x)
                right = max(right, x)
            }
            x += stepX
        }

        if (right <= left) return null

        val padX = (right - left) * 0.012
        val padY = (bottom - top) * 0.010
        return FieldBounds(
            left = left + padX,
            top = top + padY,
            right = right - padX,
            bottom = bottom - padY,
        )
    }
}
