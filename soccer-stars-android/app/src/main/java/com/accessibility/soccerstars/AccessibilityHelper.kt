package com.accessibility.soccerstars

import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.provider.Settings
import android.view.accessibility.AccessibilityManager
import androidx.appcompat.app.AlertDialog

object AccessibilityHelper {
    data class Status(
        val enabled: Boolean,
        val running: Boolean,
        val secureEnabled: Boolean,
        val managerListed: Boolean,
        val secureRaw: String?,
        val managerIds: List<String>,
    )

    fun component(context: Context): ComponentName =
        ComponentName(context, AssistAccessibilityService::class.java)

    /**
     * True when the OS has enabled our accessibility service OR it is already running.
     */
    fun isEnabled(context: Context): Boolean = probe(context).enabled

    fun isRunning(): Boolean = ForegroundAppTracker.isAccessibilityActive()

    fun probe(context: Context): Status {
        val component = component(context)
        val secureRaw = readSecureEnabledServices(context)
        val secureEnabled = matchesComponent(secureRaw, component, context.packageName)
        val managerIds = readManagerEnabledIds(context)
        val managerListed = managerIds.any { idMatchesComponent(it, component, context.packageName) }
        val running = ForegroundAppTracker.isAccessibilityActive()
        val enabled = secureEnabled || managerListed || running
        return Status(
            enabled = enabled,
            running = running,
            secureEnabled = secureEnabled,
            managerListed = managerListed,
            secureRaw = secureRaw,
            managerIds = managerIds,
        )
    }

    fun openSettings(context: Context) {
        val component = component(context)
        val intents = listOf(
            Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS).apply {
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                putExtra(Intent.EXTRA_COMPONENT_NAME, component)
            },
            Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
            Intent(Settings.ACTION_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
        )
        for (intent in intents) {
            try {
                context.startActivity(intent)
                return
            } catch (_: Exception) {
                // try next
            }
        }
    }

    fun showGuide(context: Context) {
        val status = probe(context)
        val message = if (status.enabled) {
            context.getString(R.string.accessibility_already_enabled)
        } else {
            context.getString(R.string.accessibility_help_body)
        }
        AlertDialog.Builder(context)
            .setTitle(R.string.accessibility_help_title)
            .setMessage(message)
            .setPositiveButton(R.string.accessibility_open_settings) { _, _ -> openSettings(context) }
            .setNeutralButton(R.string.accessibility_debug) { _, _ -> showDebug(context) }
            .setNegativeButton(android.R.string.cancel, null)
            .show()
    }

    fun showDebug(context: Context) {
        val s = probe(context)
        val text = buildString {
            appendLine(context.getString(R.string.accessibility_debug_title))
            appendLine()
            appendLine("نتیجه نهایی: ${if (s.enabled) "فعال ✓" else "غیرفعال ✗"}")
            appendLine("سرویس در حال اجرا: ${if (s.running) "بله" else "خیر"}")
            appendLine("Settings.Secure: ${if (s.secureEnabled) "بله" else "خیر"}")
            appendLine("AccessibilityManager: ${if (s.managerListed) "بله" else "خیر"}")
            appendLine()
            appendLine("Component:")
            appendLine(component(context).flattenToString())
            appendLine()
            appendLine("ENABLED_ACCESSIBILITY_SERVICES:")
            appendLine(s.secureRaw ?: "(خالی)")
            if (s.managerIds.isNotEmpty()) {
                appendLine()
                appendLine("Manager IDs:")
                s.managerIds.forEach { appendLine("• $it") }
            }
        }
        AlertDialog.Builder(context)
            .setTitle(R.string.accessibility_debug)
            .setMessage(text)
            .setPositiveButton(R.string.accessibility_open_settings) { _, _ -> openSettings(context) }
            .setNegativeButton(android.R.string.ok, null)
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

    internal fun matchesComponent(
        secureRaw: String?,
        component: ComponentName,
        packageName: String,
    ): Boolean = matchesComponent(secureRaw, packageName, component.className)

    internal fun matchesComponent(
        secureRaw: String?,
        packageName: String,
        className: String,
    ): Boolean {
        if (secureRaw.isNullOrBlank()) return false
        val entries = secureRaw.split(':').map { it.trim() }.filter { it.isNotEmpty() }
        return entries.any { entry -> idMatchesComponent(entry, packageName, className) }
    }

    internal fun idMatchesComponent(
        id: String,
        packageName: String,
        className: String,
    ): Boolean {
        val normalized = id.trim()
        if (normalized.isEmpty()) return false

        val full = "$packageName/$className"
        val short = ".$className"
        if (normalized.equals(full, ignoreCase = true)) return true
        if (normalized.endsWith(short, ignoreCase = true) &&
            normalized.contains(packageName, ignoreCase = true)
        ) {
            return true
        }

        try {
            ComponentName.unflattenFromString(normalized)?.let { parsed ->
                if (parsed.packageName == packageName && parsed.className == className) return true
                if (parsed.packageName == packageName &&
                    parsed.className.endsWith("AssistAccessibilityService")
                ) {
                    return true
                }
            }
        } catch (_: Exception) {
            // JVM unit tests / some OEM parsers may throw.
        }

        val lower = normalized.lowercase()
        return lower.contains(packageName.lowercase()) &&
            lower.contains("assistaccessibilityservice")
    }

    private fun idMatchesComponent(
        id: String,
        component: ComponentName,
        packageName: String,
    ): Boolean = idMatchesComponent(id, packageName, component.className)

    private fun readSecureEnabledServices(context: Context): String? {
        return try {
            Settings.Secure.getString(
                context.contentResolver,
                Settings.Secure.ENABLED_ACCESSIBILITY_SERVICES,
            )
        } catch (_: Exception) {
            null
        }
    }

    private fun readManagerEnabledIds(context: Context): List<String> {
        return try {
            val manager = context.getSystemService(Context.ACCESSIBILITY_SERVICE) as AccessibilityManager
            val mask = android.accessibilityservice.AccessibilityServiceInfo.FEEDBACK_ALL_MASK
            manager.getEnabledAccessibilityServiceList(mask).map { it.id }
        } catch (_: Exception) {
            emptyList()
        }
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
