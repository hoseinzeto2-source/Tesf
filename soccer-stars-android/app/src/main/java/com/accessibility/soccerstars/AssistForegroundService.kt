package com.accessibility.soccerstars

import android.app.Activity
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
import com.accessibility.soccerstars.vision.ScenePhase

class AssistForegroundService : Service() {
    private val mainHandler = Handler(Looper.getMainLooper())
    private var workerThread: HandlerThread? = null
    private var workerHandler: Handler? = null

    private var mediaProjection: MediaProjection? = null
    private var imageReader: ImageReader? = null
    private var virtualDisplay: VirtualDisplay? = null

    private var overlayView: GuideOverlayView? = null
    private var overlayController: OverlayController? = null
    private var windowManager: WindowManager? = null
    private lateinit var assistNotification: AssistNotification

    private var controller: AssistController? = null
    private var assistEnabled = true
    private var showDebug = false
    private var lastStatusLine = "در حال آماده‌سازی..."
    private var screenWidth = 0
    private var screenHeight = 0
    private var screenDensity = 0
    private var processScale = 0.55f
    private var lastProcessMs = 0L
    private var processing = false
    private var captureStarted = false

    private val projectionCallback = object : MediaProjection.Callback() {
        override fun onStop() {
            mainHandler.post { stopSelf() }
        }
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        assistNotification = AssistNotification(this)

        when (intent?.action) {
            AssistNotification.ACTION_STOP -> {
                stopSelf()
                return START_NOT_STICKY
            }
            AssistNotification.ACTION_TOGGLE_ASSIST -> {
                assistEnabled = !assistEnabled
                AppPreferences.setAssistEnabled(this, assistEnabled)
                refreshNotification()
                return START_STICKY
            }
            AssistNotification.ACTION_TOGGLE_DEBUG -> {
                showDebug = !showDebug
                AppPreferences.setShowDebug(this, showDebug)
                overlayView?.showDebug = showDebug
                refreshNotification()
                return START_STICKY
            }
        }

        val resultCode = intent?.getIntExtra(EXTRA_RESULT_CODE, Activity.RESULT_CANCELED)
            ?: Activity.RESULT_CANCELED
        val data = readProjectionData(intent)

        if (resultCode != Activity.RESULT_OK || data == null) {
            if (captureStarted) return START_STICKY
            stopSelf()
            return START_NOT_STICKY
        }

        PhysicsStorage.ensureDefault(applicationContext)
        processScale = AppPreferences.processScale(this)
        assistEnabled = AppPreferences.assistEnabled(this)
        showDebug = AppPreferences.showDebug(this)
        controller = AssistController(
            context = applicationContext,
            physicsPath = PhysicsStorage.physicsFile(applicationContext).absolutePath,
            rulerExtensionPx = AppPreferences.rulerExtension(this),
            showPuckPath = AppPreferences.showPuckPath(this),
        )

        startForeground(
            AssistNotification.NOTIFICATION_ID,
            assistNotification.build(lastStatusLine, assistEnabled, showDebug),
        )

        if (!captureStarted) {
            initMetrics()
            setupOverlay()
            startCapture(resultCode, data)
            captureStarted = true
        }

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

        overlayController?.destroy()
        overlayView = null
        overlayController = null
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

        val wm = windowManager ?: return
        overlayView = GuideOverlayView(this).apply {
            showLegend = AppPreferences.showLegend(this@AssistForegroundService)
            showDebug = this@AssistForegroundService.showDebug
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

        wm.addView(overlayView, params)
        overlayController = OverlayController(wm, overlayView)
        overlayController?.hide()
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
                publishFrame(image)
            } catch (_: Exception) {
                processing = false
            } finally {
                image.close()
            }
        }, workerHandler)
    }

    private fun publishFrame(image: android.media.Image) {
        val ctrl = controller ?: return
        val soccerStarsForeground = isSoccerStarsForeground()
        val accessibilityOn = AccessibilityHelper.isEnabled(this)

        if (accessibilityOn && !soccerStarsForeground) {
            val pkg = ForegroundAppTracker.currentPackage()
            val status = AccessibilityHelper.statusLabel(this, pkg)
            mainHandler.post {
                overlayController?.applyScene(ScenePhase.MENU_OR_HOME, soccerStarsForeground = false)
                lastStatusLine = status
                refreshNotification()
                processing = false
            }
            return
        }

        val full = image.toBitmap()
        val scaled = scaleBitmap(full, processScale)
        if (scaled !== full) full.recycle()

        val invScale = 1f / processScale
        val state = if (assistEnabled) {
            ctrl.process(scaled, invScale)
        } else {
            ctrl.detectOnly(scaled, invScale)
        }
        scaled.recycle()

        mainHandler.post {
            val inGame = soccerStarsForeground && state.scenePhase != ScenePhase.MENU_OR_HOME
            overlayController?.applyScene(state.scenePhase, soccerStarsForeground)
            if (inGame) {
                overlayView?.updateState(state)
            }
            lastStatusLine = state.statusText
            refreshNotification()
            processing = false
        }
    }

    private fun isSoccerStarsForeground(): Boolean {
        if (!AccessibilityHelper.isEnabled(this)) return true
        return ForegroundAppTracker.isSoccerStarsForeground()
    }

    private fun refreshNotification() {
        if (!::assistNotification.isInitialized) return
        assistNotification.update(lastStatusLine, assistEnabled, showDebug)
    }

    private fun scaleBitmap(source: Bitmap, scale: Float): Bitmap {
        if (scale >= 0.99f) return source
        val w = (source.width * scale).toInt().coerceAtLeast(180)
        val h = (source.height * scale).toInt().coerceAtLeast(320)
        return Bitmap.createScaledBitmap(source, w, h, true)
    }

    companion object {
        const val EXTRA_RESULT_CODE = "result_code"
        const val EXTRA_RESULT_DATA = "result_data"
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
