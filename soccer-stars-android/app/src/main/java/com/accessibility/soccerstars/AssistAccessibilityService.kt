package com.accessibility.soccerstars

import android.accessibilityservice.AccessibilityService
import android.view.accessibility.AccessibilityEvent

class AssistAccessibilityService : AccessibilityService() {
    override fun onServiceConnected() {
        super.onServiceConnected()
        ForegroundAppTracker.setAccessibilityRunning(true)
    }

    override fun onAccessibilityEvent(event: AccessibilityEvent?) {
        if (event == null) return
        when (event.eventType) {
            AccessibilityEvent.TYPE_WINDOW_STATE_CHANGED,
            AccessibilityEvent.TYPE_WINDOWS_CHANGED,
            -> {
                val pkg = event.packageName?.toString() ?: return
                ForegroundAppTracker.update(pkg)
            }
        }
    }

    override fun onInterrupt() {
        // no-op
    }

    override fun onDestroy() {
        ForegroundAppTracker.setAccessibilityRunning(false)
        super.onDestroy()
    }
}
