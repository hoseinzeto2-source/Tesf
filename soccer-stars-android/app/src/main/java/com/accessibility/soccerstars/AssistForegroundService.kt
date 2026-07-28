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
import android.os.SystemClock
import android.provider.Settings
import android.util.DisplayMetrics
import android.view.Gravity
import android.view.WindowManager
import com.accessibility.soccerstars.physics.PhysicsStorage
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

    private var controller: AssistController? = null
    private var screenWidth = 0
    private var screenHeight = 0
    private var screenDensity = 0
    private var processScale = 0.45f
    private var lastProcessMs = 0L
    private var processing = false

    private val projectionCallback = object : MediaProjection.Callback() {
        override fun onStop() {
            mainHandler.post { stopSelf() }
        }
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            stopSelf()
            return START_NOT_STICKY
        }

        val resultCode = intent?.getIntExtra(EXTRA_RESULT_CODE, Activity.RESULT_CANCELED)
            ?: Activity.RESULT_CANCELED
        val data = readProjectionData(intent)

        if (resultCode != Activity.RESULT_OK || data == null) {
            stopSelf()
            return START_NOT_STICKY
        }

        PhysicsStorage.ensureDefault(applicationContext)
        processScale = AppPreferences.processScale(this)
        controller = AssistController(
            physicsPath = PhysicsStorage.physicsFile(applicationContext).absolutePath,
            rulerExtensionPx = AppPreferences.rulerExtension(this),
            showPuckPath = AppPreferences.showPuckPath(this),
        )

        startForeground(NOTIFICATION_ID, buildNotification())
        initMetrics()
        setupOverlay()
        startCapture(resultCode, data)
        return START_STICKY
    }

    override fun onDestroy() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            mediaProjection?.unregisterCallback(projectionCallback)
        }
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

    private fun readProjectionData(intent: Intent?): Intent? {
        if (intent == null) return null
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            intent.getParcelableExtra(EXTRA_RESULT_DATA, Intent::class.java)
        } else {
            @Suppress("DEPRECATION")
            intent.getParcelableExtra(EXTRA_RESULT_DATA)
        }
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

        overlayView = GuideOverlayView(this).apply {
            showLegend = AppPreferences.showLegend(this@AssistForegroundService)
        }

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
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            mediaProjection?.registerCallback(projectionCallback, mainHandler)
        }

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
            val now = SystemClock.elapsedRealtime()
            if (processing || now - lastProcessMs < FRAME_INTERVAL_MS) {
                reader.acquireLatestImage()?.close()
                return@setOnImageAvailableListener
            }
            lastProcessMs = now

            val image = reader.acquireLatestImage() ?: return@setOnImageAvailableListener
            processing = true
            try {
                val full = image.toBitmap()
                val scaled = scaleBitmap(full, processScale)
                if (scaled !== full) full.recycle()

                val ctrl = controller ?: return@setOnImageAvailableListener
                val invScale = 1f / processScale
                val state = ctrl.process(scaled, invScale)
                scaled.recycle()

                mainHandler.post {
                    overlayView?.updateState(state)
                    processing = false
                }
            } catch (_: Exception) {
                processing = false
            } finally {
                image.close()
            }
        }, workerHandler)
    }

    private fun scaleBitmap(source: Bitmap, scale: Float): Bitmap {
        if (scale >= 0.99f) return source
        val w = (source.width * scale).toInt().coerceAtLeast(180)
        val h = (source.height * scale).toInt().coerceAtLeast(320)
        return Bitmap.createScaledBitmap(source, w, h, true)
    }

    private fun buildNotification(): Notification {
        val channelId = "soccer_stars_assist"
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                channelId,
                "Soccer Stars Assist",
                NotificationManager.IMPORTANCE_LOW,
            )
            getSystemService(NotificationManager::class.java).createNotificationChannel(channel)
        }

        val stopIntent = Intent(this, AssistForegroundService::class.java).apply { action = ACTION_STOP }
        val stopPending = PendingIntent.getService(
            this, 0, stopIntent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        val settingsIntent = Intent(this, SettingsActivity::class.java)
        val settingsPending = PendingIntent.getActivity(
            this, 1, settingsIntent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        return NotificationCompat.Builder(this, channelId)
            .setContentTitle("Soccer Stars Assist فعال است")
            .setContentText("خط‌کش و مسیر توپ روی بازی نمایش داده می‌شود")
            .setSmallIcon(android.R.drawable.ic_menu_compass)
            .addAction(android.R.drawable.ic_menu_preferences, "تنظیمات", settingsPending)
            .addAction(android.R.drawable.ic_menu_close_clear_cancel, "توقف", stopPending)
            .setOngoing(true)
            .build()
    }

    companion object {
        const val EXTRA_RESULT_CODE = "result_code"
        const val EXTRA_RESULT_DATA = "result_data"
        private const val ACTION_STOP = "com.accessibility.soccerstars.STOP"
        private const val NOTIFICATION_ID = 42
        private const val FRAME_INTERVAL_MS = 90L

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
    buffer.rewind()
    val pixelStride = plane.pixelStride
    val rowStride = plane.rowStride
    val rowPadding = rowStride - pixelStride * width

    val bitmap = Bitmap.createBitmap(
        width + rowPadding / pixelStride,
        height,
        Bitmap.Config.ARGB_8888,
    )
    bitmap.copyPixelsFromBuffer(buffer)
    return if (rowPadding == 0) bitmap else Bitmap.createBitmap(bitmap, 0, 0, width, height)
}
