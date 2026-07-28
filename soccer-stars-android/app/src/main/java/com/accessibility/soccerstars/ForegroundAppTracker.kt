package com.accessibility.soccerstars

import java.util.concurrent.atomic.AtomicBoolean
import java.util.concurrent.atomic.AtomicReference

/**
 * Tracks which app is on screen. Updated by [AssistAccessibilityService].
 */
object ForegroundAppTracker {
    const val SOCCER_STARS_PACKAGE = "com.miniclip.soccerstars"

    private val packageName = AtomicReference<String?>(null)
    private val soccerStarsActive = AtomicBoolean(false)
    private val accessibilityRunning = AtomicBoolean(false)

    fun update(packageName: String?) {
        this.packageName.set(packageName)
        soccerStarsActive.set(packageName == SOCCER_STARS_PACKAGE)
    }

    fun setAccessibilityRunning(running: Boolean) {
        accessibilityRunning.set(running)
    }

    fun currentPackage(): String? = packageName.get()

    fun isSoccerStarsForeground(): Boolean = soccerStarsActive.get()

    fun isAccessibilityActive(): Boolean = accessibilityRunning.get()

    fun isLauncherPackage(pkg: String): Boolean {
        val lower = pkg.lowercase()
        return lower.contains("launcher") ||
            lower.endsWith(".home") ||
            lower == "com.miui.home" ||
            lower == "com.android.systemui"
    }
}
