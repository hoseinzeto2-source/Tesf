package com.accessibility.soccerstars

import android.content.Context
import android.view.accessibility.AccessibilityManager

class AccessibilityStateWatcher(
    context: Context,
    private val onChanged: () -> Unit,
) {
    private val appContext = context.applicationContext
    private val manager = appContext.getSystemService(Context.ACCESSIBILITY_SERVICE) as AccessibilityManager

    private val accessibilityListener = AccessibilityManager.AccessibilityStateChangeListener {
        onChanged()
    }

    private val servicesListener = AccessibilityManager.AccessibilityServicesStateChangeListener {
        onChanged()
    }

    fun start() {
        manager.addAccessibilityStateChangeListener(accessibilityListener)
        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.TIRAMISU) {
            manager.addAccessibilityServicesStateChangeListener(
                appContext.mainExecutor,
                servicesListener,
            )
        }
    }

    fun stop() {
        manager.removeAccessibilityStateChangeListener(accessibilityListener)
        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.TIRAMISU) {
            manager.removeAccessibilityServicesStateChangeListener(servicesListener)
        }
    }
}
