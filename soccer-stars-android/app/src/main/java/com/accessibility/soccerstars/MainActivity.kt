package com.accessibility.soccerstars

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.content.res.ColorStateList
import android.media.projection.MediaProjectionManager
import android.os.Build
import android.os.Bundle
import android.view.View
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import com.accessibility.soccerstars.databinding.ActivityMainBinding
import com.accessibility.soccerstars.physics.PhysicsStorage

class MainActivity : AppCompatActivity() {
    private lateinit var binding: ActivityMainBinding

    private val notificationPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) { updateUi() }

    private val screenCaptureLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult(),
    ) { result ->
        if (result.resultCode == RESULT_OK && result.data != null) {
            AssistForegroundService.start(this, result.resultCode, result.data!!)
            Toast.makeText(this, R.string.toast_started, Toast.LENGTH_LONG).show()
            moveTaskToBack(true)
        } else {
            Toast.makeText(this, R.string.toast_capture_denied, Toast.LENGTH_LONG).show()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        try {
            PhysicsStorage.ensureDefault(this)
        } catch (_: Exception) {
            // Assets fallback handled inside PhysicsStorage.loadConfig
        }

        binding.startButton.setOnClickListener { startAssistFlow() }
        binding.settingsButton.setOnClickListener {
            startActivity(Intent(this, SettingsActivity::class.java))
        }
        binding.grantOverlayButton.setOnClickListener { requestOverlayPermission() }
        binding.grantAccessibilityButton.setOnClickListener { requestAccessibility() }
        binding.overlayHelpButton.setOnClickListener {
            OverlayPermissionHelper.showManualGuideIfNeeded(this)
        }
        binding.stepOverlayCard.setOnClickListener {
            if (!OverlayPermissionHelper.isGranted(this)) {
                requestOverlayPermission()
            }
        }
        binding.stepAccessibilityCard.setOnClickListener {
            if (!AccessibilityHelper.isEnabled(this)) {
                AccessibilityHelper.showGuide(this)
            }
        }
        requestNotificationPermissionIfNeeded()
        updateUi()
    }

    override fun onResume() {
        super.onResume()
        updateUi()
    }

    private fun requestNotificationPermissionIfNeeded() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) return
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS)
            == PackageManager.PERMISSION_GRANTED
        ) {
            return
        }
        notificationPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
    }

    private fun updateUi() {
        val overlayGranted = OverlayPermissionHelper.isGranted(this)
        val accessibilityGranted = AccessibilityHelper.isEnabled(this)
        val ready = overlayGranted && accessibilityGranted

        setStepState(
            binding.stepOverlayIcon,
            binding.stepOverlayStatus,
            overlayGranted,
            R.string.step_overlay_done,
            R.string.step_overlay_pending,
        )

        setStepState(
            binding.stepAccessibilityIcon,
            binding.stepAccessibilityStatus,
            accessibilityGranted,
            R.string.step_accessibility_done,
            R.string.step_accessibility_pending,
        )

        setStepState(
            binding.stepCaptureIcon,
            binding.stepCaptureStatus,
            ready,
            R.string.step_capture_ready,
            R.string.step_capture_locked,
        )

        setStepState(
            binding.stepPlayIcon,
            binding.stepPlayStatus,
            ready,
            R.string.step_play_ready,
            R.string.step_play_locked,
        )

        binding.grantOverlayButton.visibility = if (overlayGranted) View.GONE else View.VISIBLE
        binding.overlayHelpButton.visibility = if (overlayGranted) View.GONE else View.VISIBLE
        binding.grantAccessibilityButton.visibility = if (accessibilityGranted) View.GONE else View.VISIBLE
        binding.startButton.isEnabled = ready
        binding.startButton.alpha = if (ready) 1f else 0.55f
        binding.statusChip.text = getString(
            when {
                !overlayGranted -> R.string.status_need_overlay
                !accessibilityGranted -> R.string.status_need_accessibility
                else -> R.string.status_ready
            },
        )
        binding.statusChip.setChipBackgroundColorResource(
            if (ready) R.color.chip_ok_bg else R.color.chip_warn_bg,
        )
        binding.statusChip.setTextColor(
            ContextCompat.getColor(
                this,
                if (ready) R.color.chip_ok_text else R.color.chip_warn_text,
            ),
        )
    }

    private fun setStepState(
        iconView: View,
        statusView: android.widget.TextView,
        ok: Boolean,
        okText: Int,
        pendingText: Int,
    ) {
        val color = ContextCompat.getColor(this, if (ok) R.color.accent else R.color.text_muted)
        iconView.backgroundTintList = ColorStateList.valueOf(color)
        statusView.setText(if (ok) okText else pendingText)
        statusView.setTextColor(
            ContextCompat.getColor(this, if (ok) R.color.text_primary else R.color.text_secondary),
        )
    }

    private fun requestOverlayPermission() {
        OverlayPermissionHelper.openSettings(this)
        Toast.makeText(this, R.string.overlay_toast_return, Toast.LENGTH_LONG).show()
    }

    private fun requestAccessibility() {
        AccessibilityHelper.showGuide(this)
    }

    private fun startAssistFlow() {
        if (!OverlayPermissionHelper.isGranted(this)) {
            OverlayPermissionHelper.showManualGuideIfNeeded(this)
            return
        }
        if (!AccessibilityHelper.isEnabled(this)) {
            AccessibilityHelper.showGuide(this)
            return
        }
        val manager = getSystemService(MediaProjectionManager::class.java)
        screenCaptureLauncher.launch(manager.createScreenCaptureIntent())
    }
}
