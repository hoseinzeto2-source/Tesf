package com.accessibility.soccerstars

import android.app.Activity
import android.content.Intent
import android.media.projection.MediaProjectionManager
import android.net.Uri
import android.os.Bundle
import android.provider.Settings
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import com.accessibility.soccerstars.databinding.ActivityMainBinding

class MainActivity : AppCompatActivity() {
    private lateinit var binding: ActivityMainBinding

    private val overlayPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult(),
    ) {
        updateUi()
    }

    private val screenCaptureLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult(),
    ) { result ->
        if (result.resultCode == Activity.RESULT_OK && result.data != null) {
            AssistForegroundService.start(this, result.resultCode, result.data!!)
            Toast.makeText(this, "ابزار فعال شد — Soccer Stars را باز کنید", Toast.LENGTH_LONG).show()
            finish()
        } else {
            Toast.makeText(this, "بدون اجازه ضبط صفحه کار نمی‌کند", Toast.LENGTH_LONG).show()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.startButton.setOnClickListener { startAssistFlow() }
        updateUi()
    }

    override fun onResume() {
        super.onResume()
        updateUi()
    }

    private fun updateUi() {
        val overlayGranted = Settings.canDrawOverlays(this)
        binding.statusOverlay.text = if (overlayGranted) {
            "✓ اجازه نمایش روی بازی"
        } else {
            "✗ اجازه نمایش روی بازی لازم است"
        }
        binding.startButton.isEnabled = overlayGranted
    }

    private fun startAssistFlow() {
        if (!Settings.canDrawOverlays(this)) {
            val intent = Intent(
                Settings.ACTION_MANAGE_OVERLAY_PERMISSION,
                Uri.parse("package:$packageName"),
            )
            overlayPermissionLauncher.launch(intent)
            return
        }

        val manager = getSystemService(MediaProjectionManager::class.java)
        screenCaptureLauncher.launch(manager.createScreenCaptureIntent())
    }
}
