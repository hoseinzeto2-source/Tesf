package com.accessibility.soccerstars

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.core.app.NotificationCompat

class AssistNotification(private val context: Context) {
    fun build(statusLine: String, assistOn: Boolean, debugOn: Boolean): Notification {
        ensureChannel()

        val toggleAssist = servicePendingIntent(ACTION_TOGGLE_ASSIST, 10)
        val toggleDebug = servicePendingIntent(ACTION_TOGGLE_DEBUG, 11)
        val settings = activityPendingIntent(SettingsActivity::class.java, 12)
        val stop = servicePendingIntent(ACTION_STOP, 13)

        val assistLabel = if (assistOn) "راهنما: روشن" else "راهنما: خاموش"
        val debugLabel = if (debugOn) "دیباگ: روشن" else "دیباگ: خاموش"

        return NotificationCompat.Builder(context, CHANNEL_ID)
            .setContentTitle("Soccer Stars Assist")
            .setContentText(statusLine)
            .setStyle(NotificationCompat.BigTextStyle().bigText(statusLine))
            .setSmallIcon(android.R.drawable.ic_menu_compass)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setContentIntent(activityPendingIntent(MainActivity::class.java, 14))
            .addAction(android.R.drawable.ic_menu_view, assistLabel, toggleAssist)
            .addAction(android.R.drawable.ic_menu_info_details, debugLabel, toggleDebug)
            .addAction(android.R.drawable.ic_menu_preferences, "تنظیمات", settings)
            .addAction(android.R.drawable.ic_menu_close_clear_cancel, "توقف", stop)
            .build()
    }

    fun update(statusLine: String, assistOn: Boolean, debugOn: Boolean) {
        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        manager.notify(NOTIFICATION_ID, build(statusLine, assistOn, debugOn))
    }

    private fun ensureChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        val channel = NotificationChannel(
            CHANNEL_ID,
            "Soccer Stars Assist",
            NotificationManager.IMPORTANCE_DEFAULT,
        ).apply {
            description = "کنترل راهنما — راهنما، دیباگ، تنظیمات، توقف"
            setShowBadge(true)
        }
        manager.createNotificationChannel(channel)
    }

    private fun servicePendingIntent(action: String, requestCode: Int): PendingIntent {
        val intent = Intent(context, AssistForegroundService::class.java).apply {
            this.action = action
        }
        return PendingIntent.getService(
            context,
            requestCode,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
    }

    private fun activityPendingIntent(cls: Class<*>, requestCode: Int): PendingIntent {
        val intent = Intent(context, cls).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        return PendingIntent.getActivity(
            context,
            requestCode,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
    }

    companion object {
        const val CHANNEL_ID = "soccer_stars_assist"
        const val NOTIFICATION_ID = 42
        const val ACTION_STOP = "com.accessibility.soccerstars.STOP"
        const val ACTION_TOGGLE_ASSIST = "com.accessibility.soccerstars.TOGGLE_ASSIST"
        const val ACTION_TOGGLE_DEBUG = "com.accessibility.soccerstars.TOGGLE_DEBUG"
    }
}
