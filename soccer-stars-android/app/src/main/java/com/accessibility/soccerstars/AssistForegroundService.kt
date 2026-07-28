package com.accessibility.soccerstars

import android.app.Activity
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.PixelFormat
import android.hardware.display.DisplayManager
import android.hardware.display.VirtualDisplay
import android.media.ImageReader
import android.media.projection.MediaProjection
import android.media.projection.MediaProjectionManager
import android.os.Build
import android.os.Handler
import android.os.HandlerThread
import android.os.IBinder
import android.os.Looper
import android.provider.Settings
import android.util.DisplayMetrics
import android.view.Gravity
import android.view.WindowManager
import androidx.core.app.NotificationCompat

class AssistForegroundService : Service() {
    private val mainHandler = Handler(Looper.getMainLooper())
    private var workerThread: HandlerThread? = null
    private var workerHandler: Handler? = null

    private var mediaProjection: MediaProjection? = null
    private var imageReader: ImageReader? = null
    private var virtualDisplay: VirtualDisplay? = null

    private var overlayView: GuideOverlayView? = null
    private var windowManager: WindowManager? = null

    private val controller = AssistController()
    private var screenWidth = 0
    private var screenHeight = 0
    private var screenDensity = 0

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            stopSelf()
            return START_NOT_STICKY
        }

        val resultCode = intent?.getIntExtra(EXTRA_RESULT_CODE, Activity.RESULT_CANCELED)
            ?: Activity.RESULT_CANCELED
        val data = intent?.getParcelableExtra<Intent>(EXTRA_RESULT_DATA)

        if (resultCode != Activity.RESULT_OK || data == null) {
            stopSelf()
            return START_NOT_STICKY
        }

        startForeground(NOTIFICATION_ID, buildNotification())
        initMetrics()
        setupOverlay()
        startCapture(resultCode, data)
        return START_STICKY
    }

    override fun onDestroy() {
        workerThread?.quitSafely()
        workerThread = null
        workerHandler = null

        virtualDisplay?.release()
        imageReader?.close()
        mediaProjection?.stop()

        overlayView?.let { windowManager?.removeView(it) }
        overlayView = null
        super.onDestroy()
    }

    private fun initMetrics() {
        val metrics = DisplayMetrics()
        windowManager = getSystemService(WINDOW_SERVICE) as WindowManager
        @Suppress("DEPRECATION")
        windowManager!!.defaultDisplay.getRealMetrics(metrics)
        screenWidth = metrics.widthPixels
        screenHeight = metrics.heightPixels
        screenDensity = metrics.densityDpi
    }

    private fun setupOverlay() {
        if (!Settings.canDrawOverlays(this)) return

        overlayView = GuideOverlayView(this)
        val layoutType = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY
        } else {
            @Suppress("DEPRECATION")
            WindowManager.LayoutParams.TYPE_PHONE
        }

        val params = WindowManager.LayoutParams(
            WindowManager.LayoutParams.MATCH_PARENT,
            WindowManager.LayoutParams.MATCH_PARENT,
            layoutType,
            WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or
                WindowManager.LayoutParams.FLAG_NOT_TOUCHABLE or
                WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN or
                WindowManager.LayoutParams.FLAG_LAYOUT_NO_LIMITS,
            PixelFormat.TRANSLUCENT,
        ).apply {
            gravity = Gravity.TOP or Gravity.START
        }

        windowManager?.addView(overlayView, params)
    }

    private fun startCapture(resultCode: Int, data: Intent) {
        val projectionManager = getSystemService(MediaProjectionManager::class.java)
        mediaProjection = projectionManager.getMediaProjection(resultCode, data)

        workerThread = HandlerThread("assist-capture").also { it.start() }
        workerHandler = Handler(workerThread!!.looper)

        imageReader = ImageReader.newInstance(screenWidth, screenHeight, PixelFormat.RGBA_8888, 2)
        virtualDisplay = mediaProjection?.createVirtualDisplay(
            "SoccerStarsAssist",
            screenWidth,
            screenHeight,
            screenDensity,
            DisplayManager.VIRTUAL_DISPLAY_FLAG_AUTO_MIRROR,
            imageReader?.surface,
            null,
            workerHandler,
        )

        imageReader?.setOnImageAvailableListener({ reader ->
            val image = reader.acquireLatestImage() ?: return@setOnImageAvailableListener
            try {
                val bitmap = image.toBitmap()
                val state = controller.process(bitmap)
                bitmap.recycle()
                mainHandler.post { overlayView?.updateState(state) }
            } finally {
                image.close()
            }
        }, workerHandler)
    }

    private fun buildNotification(): Notification {
        val channelId = "soccer_stars_assist"
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                channelId,
                "Soccer Stars Assist",
                NotificationManager.IMPORTANCE_LOW,
            )
            val manager = getSystemService(NotificationManager::class.java)
            manager.createNotificationChannel(channel)
        }

        val stopIntent = Intent(this, AssistForegroundService::class.java).apply {
            action = ACTION_STOP
        }
        val stopPending = PendingIntent.getService(
            this,
            0,
            stopIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        return NotificationCompat.Builder(this, channelId)
            .setContentTitle("Soccer Stars Assist فعال است")
            .setContentText("خط‌کش و مسیر توپ روی بازی نمایش داده می‌شود")
            .setSmallIcon(android.R.drawable.ic_menu_compass)
            .addAction(android.R.drawable.ic_menu_close_clear_cancel, "توقف", stopPending)
            .setOngoing(true)
            .build()
    }

    companion object {
        const val EXTRA_RESULT_CODE = "result_code"
        const val EXTRA_RESULT_DATA = "result_data"
        private const val ACTION_STOP = "com.accessibility.soccerstars.STOP"
        private const val NOTIFICATION_ID = 42

        fun start(context: Context, resultCode: Int, data: Intent) {
            val intent = Intent(context, AssistForegroundService::class.java).apply {
                putExtra(EXTRA_RESULT_CODE, resultCode)
                putExtra(EXTRA_RESULT_DATA, data)
            }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(intent)
            } else {
                context.startService(intent)
            }
        }
    }
}

private fun android.media.Image.toBitmap(): Bitmap {
    val plane = planes[0]
    val buffer = plane.buffer
    val pixelStride = plane.pixelStride
    val rowStride = plane.rowStride
    val rowPadding = rowStride - pixelStride * width

    val bitmap = Bitmap.createBitmap(
        width + rowPadding / pixelStride,
        height,
        Bitmap.Config.ARGB_8888,
    )
    bitmap.copyPixelsFromBuffer(buffer)
    return Bitmap.createBitmap(bitmap, 0, 0, width, height)
}
