package com.accessibility.soccerstars

import android.content.Intent
import android.content.res.ColorStateList
import android.media.projection.MediaProjectionManager
import android.net.Uri
import android.os.Bundle
import android.provider.Settings
import android.view.View
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import com.accessibility.soccerstars.databinding.ActivityMainBinding
import com.accessibility.soccerstars.physics.PhysicsStorage

class MainActivity : AppCompatActivity() {
    private lateinit var binding: ActivityMainBinding

    private val overlayPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult(),
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
        updateUi()
    }

    override fun onResume() {
        super.onResume()
        updateUi()
    }

    private fun updateUi() {
        val overlayGranted = Settings.canDrawOverlays(this)

        setStepState(
            binding.stepOverlayIcon,
            binding.stepOverlayStatus,
            overlayGranted,
            R.string.step_overlay_done,
            R.string.step_overlay_pending,
        )

        setStepState(
            binding.stepCaptureIcon,
            binding.stepCaptureStatus,
            overlayGranted,
            R.string.step_capture_ready,
            R.string.step_capture_locked,
        )

        setStepState(
            binding.stepPlayIcon,
            binding.stepPlayStatus,
            overlayGranted,
            R.string.step_play_ready,
            R.string.step_play_locked,
        )

        binding.grantOverlayButton.visibility = if (overlayGranted) View.GONE else View.VISIBLE
        binding.startButton.isEnabled = overlayGranted
        binding.startButton.alpha = if (overlayGranted) 1f else 0.55f
        binding.statusChip.text = getString(
            if (overlayGranted) R.string.status_ready else R.string.status_need_overlay,
        )
        binding.statusChip.setChipBackgroundColorResource(
            if (overlayGranted) R.color.chip_ok_bg else R.color.chip_warn_bg,
        )
        binding.statusChip.setTextColor(
            ContextCompat.getColor(
                this,
                if (overlayGranted) R.color.chip_ok_text else R.color.chip_warn_text,
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
        overlayPermissionLauncher.launch(
            Intent(
                Settings.ACTION_MANAGE_OVERLAY_PERMISSION,
                Uri.parse("package:$packageName"),
            ),
        )
    }

    private fun startAssistFlow() {
        if (!Settings.canDrawOverlays(this)) {
            requestOverlayPermission()
            return
        }
        val manager = getSystemService(MediaProjectionManager::class.java)
        screenCaptureLauncher.launch(manager.createScreenCaptureIntent())
    }
}
