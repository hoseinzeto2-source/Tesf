package com.accessibility.soccerstars

import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.provider.Settings
import android.text.TextUtils
import android.view.accessibility.AccessibilityManager
import androidx.appcompat.app.AlertDialog

object AccessibilityHelper {
    private fun serviceId(context: Context): String =
        ComponentName(context, AssistAccessibilityService::class.java).flattenToString()

    fun isEnabled(context: Context): Boolean {
        val manager = context.getSystemService(Context.ACCESSIBILITY_SERVICE) as AccessibilityManager
        val enabled = manager.getEnabledAccessibilityServiceList(
            android.accessibilityservice.AccessibilityServiceInfo.FEEDBACK_GENERIC,
        )
        val target = serviceId(context)
        return enabled.any { it.id == target }
    }

    fun openSettings(context: Context) {
        try {
            context.startActivity(
                Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
            )
        } catch (_: Exception) {
            context.startActivity(
                Intent(Settings.ACTION_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
            )
        }
    }

    fun showGuide(context: Context) {
        AlertDialog.Builder(context)
            .setTitle(R.string.accessibility_help_title)
            .setMessage(R.string.accessibility_help_body)
            .setPositiveButton(R.string.accessibility_open_settings) { _, _ -> openSettings(context) }
            .setNegativeButton(android.R.string.cancel, null)
            .show()
    }

    fun statusLabel(context: Context, packageName: String?): String {
        if (packageName == null) {
            return context.getString(R.string.status_waiting_game)
        }
        if (packageName == ForegroundAppTracker.SOCCER_STARS_PACKAGE) {
            return context.getString(R.string.status_in_game)
        }
        if (ForegroundAppTracker.isLauncherPackage(packageName)) {
            return context.getString(R.string.status_on_home_screen)
        }
        return context.getString(R.string.status_other_app, friendlyAppName(context, packageName))
    }

    private fun friendlyAppName(context: Context, packageName: String): String {
        return try {
            val pm = context.packageManager
            val info = pm.getApplicationInfo(packageName, 0)
            pm.getApplicationLabel(info).toString()
        } catch (_: Exception) {
            packageName
        }
    }
}
