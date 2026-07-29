package com.accessibility.soccerstars

import java.util.concurrent.atomic.AtomicBoolean
import java.util.concurrent.atomic.AtomicReference

/**
 * Tracks which app is on screen. Updated by [AssistAccessibilityService].
 */
object ForegroundAppTracker {
    const val SOCCER_STARS_PACKAGE = "com.miniclip.soccerstars"
    const val OWN_PACKAGE = "com.accessibility.soccerstars"

    private val packageName = AtomicReference<String?>(null)
    private val soccerStarsActive = AtomicBoolean(false)
    private val accessibilityRunning = AtomicBoolean(false)

    fun update(packageName: String?) {
        if (packageName.isNullOrBlank()) return
        // Overlay / assist UI events must not replace the real foreground game package.
        if (isIgnoredPackage(packageName)) return
        this.packageName.set(packageName)
        soccerStarsActive.set(isSoccerStarsPackage(packageName))
    }

    fun isSoccerStarsPackage(pkg: String): Boolean {
        val lower = pkg.lowercase()
        if (lower == OWN_PACKAGE) return false
        return lower.contains("soccerstars") ||
            (lower.contains("miniclip") && lower.contains("soccer"))
    }

    private fun isIgnoredPackage(pkg: String): Boolean {
        val lower = pkg.lowercase()
        return lower == OWN_PACKAGE ||
            lower.contains("systemui") ||
            lower.endsWith(".assist")
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
