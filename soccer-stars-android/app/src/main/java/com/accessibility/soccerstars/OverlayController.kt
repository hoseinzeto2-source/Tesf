package com.accessibility.soccerstars

import android.view.View
import android.view.WindowManager
import com.accessibility.soccerstars.vision.ScenePhase

/**
 * Shows the draw overlay only while Soccer Stars is on screen.
 * Keeps the home screen and other apps fully touchable.
 */
class OverlayController(
    private val windowManager: WindowManager,
    private var overlayView: GuideOverlayView?,
) {
    private var attached = overlayView != null
    private var visible = false

    fun setView(view: GuideOverlayView?) {
        overlayView = view
        attached = view != null
    }

    fun applyScene(scene: ScenePhase, soccerStarsForeground: Boolean) {
        val shouldShow = soccerStarsForeground && scene != ScenePhase.MENU_OR_HOME
        if (shouldShow) show() else hide()
    }

    fun show() {
        if (!attached || visible) return
        overlayView?.visibility = View.VISIBLE
        visible = true
    }

    fun hide() {
        if (!attached || !visible) {
            overlayView?.visibility = View.GONE
            overlayView?.clearDisplay()
            visible = false
            return
        }
        overlayView?.clearDisplay()
        overlayView?.visibility = View.GONE
        visible = false
    }

    fun destroy() {
        overlayView?.let {
            if (attached) {
                try {
                    windowManager.removeView(it)
                } catch (_: Exception) {
                    // already removed
                }
            }
        }
        overlayView = null
        attached = false
        visible = false
    }
}
