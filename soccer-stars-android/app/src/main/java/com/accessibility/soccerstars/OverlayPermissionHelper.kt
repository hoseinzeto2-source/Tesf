package com.accessibility.soccerstars

import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.appcompat.app.AlertDialog

object OverlayPermissionHelper {
    fun isGranted(context: Context): Boolean = Settings.canDrawOverlays(context)

    fun openSettings(context: Context) {
        val pkg = context.packageName
        val intents = buildOverlayIntents(context, pkg)
        for (intent in intents) {
            if (tryStart(context, intent)) return
        }
        openAppDetails(context, pkg)
    }

    fun showManualGuideIfNeeded(context: Context) {
        if (isGranted(context)) return
        AlertDialog.Builder(context)
            .setTitle(R.string.overlay_help_title)
            .setMessage(manualGuideText(context))
            .setPositiveButton(R.string.overlay_open_settings) { _, _ -> openSettings(context) }
            .setNegativeButton(R.string.overlay_open_app_info) { _, _ ->
                openAppDetails(context, context.packageName)
            }
            .setNeutralButton(android.R.string.cancel, null)
            .show()
    }

    fun manualGuideText(context: Context): String {
        val appLabel = context.applicationInfo.loadLabel(context.packageManager).toString()
        val manufacturer = Build.MANUFACTURER.lowercase()
        val base = context.getString(R.string.overlay_help_base, appLabel)
        val oem = when {
            manufacturer.contains("xiaomi") || manufacturer.contains("redmi") || manufacturer.contains("poco") ->
                context.getString(R.string.overlay_help_miui, appLabel)
            manufacturer.contains("samsung") ->
                context.getString(R.string.overlay_help_samsung, appLabel)
            manufacturer.contains("huawei") || manufacturer.contains("honor") ->
                context.getString(R.string.overlay_help_huawei, appLabel)
            manufacturer.contains("oppo") || manufacturer.contains("realme") || manufacturer.contains("oneplus") ->
                context.getString(R.string.overlay_help_oppo, appLabel)
            else -> context.getString(R.string.overlay_help_generic, appLabel)
        }
        return "$base\n\n$oem"
    }

    private fun buildOverlayIntents(context: Context, pkg: String): List<Intent> {
        val list = mutableListOf<Intent>()

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            list += Intent("android.settings.MANAGE_APP_OVERLAY_PERMISSION", Uri.parse("package:$pkg"))
        }

        list += Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION, Uri.parse("package:$pkg"))
        list += Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION)

        list += Intent("miui.intent.action.APP_PERM_EDITOR")
            .putExtra("extra_pkgname", pkg)

        list += Intent().apply {
            setClassName(
                "com.miui.securitycenter",
                "com.miui.permcenter.permissions.PermissionsEditorActivity",
            )
            putExtra("extra_pkgname", pkg)
        }

        list += Intent().apply {
            setClassName(
                "com.miui.securitycenter",
                "com.miui.permcenter.permissions.AppPermissionsEditorActivity",
            )
            putExtra("extra_pkgname", pkg)
        }

        list += Intent().apply {
            setClassName(
                "com.coloros.safecenter",
                "com.coloros.safecenter.permission.floatwindow.FloatWindowListActivity",
            )
        }

        list += Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:$pkg"))
        return list
    }

    private fun openAppDetails(context: Context, pkg: String) {
        tryStart(
            context,
            Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:$pkg")),
        )
    }

    private fun tryStart(context: Context, intent: Intent): Boolean {
        return try {
            intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            context.startActivity(intent)
            true
        } catch (_: ActivityNotFoundException) {
            false
        } catch (_: SecurityException) {
            false
        }
    }
}
