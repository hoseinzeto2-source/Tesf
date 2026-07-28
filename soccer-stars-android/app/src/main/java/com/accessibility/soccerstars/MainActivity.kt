package com.accessibility.soccerstars

import android.content.Intent
import android.media.projection.MediaProjectionManager
import android.net.Uri
import android.os.Bundle
import android.provider.Settings
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import com.accessibility.soccerstars.databinding.ActivityMainBinding
import java.io.File

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
      Toast.makeText(this, "فعال شد — Soccer Stars را باز کنید", Toast.LENGTH_LONG).show()
      moveTaskToBack(true)
    } else {
      Toast.makeText(this, "بدون اجازه ضبط صفحه امکان‌پذیر نیست", Toast.LENGTH_LONG).show()
    }
  }

  override fun onCreate(savedInstanceState: Bundle?) {
    super.onCreate(savedInstanceState)
    binding = ActivityMainBinding.inflate(layoutInflater)
    setContentView(binding.root)

    ensureDefaultPhysicsFile()
    binding.startButton.setOnClickListener { startAssistFlow() }
    binding.settingsButton.setOnClickListener {
      startActivity(Intent(this, SettingsActivity::class.java))
    }
    updateUi()
  }

  override fun onResume() {
    super.onResume()
    updateUi()
  }

  private fun ensureDefaultPhysicsFile() {
    val dir = File("/sdcard/SoccerStarsAssist")
    if (!dir.exists()) dir.mkdirs()
    val target = File(dir, "physics.json")
    if (!target.exists()) {
      assets.open("physics.json").use { input ->
        target.outputStream().use { output -> input.copyTo(output) }
      }
    }
  }

  private fun updateUi() {
    val overlayGranted = Settings.canDrawOverlays(this)
    binding.statusOverlay.text = if (overlayGranted) {
      "✓ اجازه نمایش روی بازی داده شده"
    } else {
      "✗ لطفاً اجازه «نمایش روی برنامه‌ها» را بدهید"
    }
    binding.startButton.isEnabled = overlayGranted
  }

  private fun startAssistFlow() {
    if (!Settings.canDrawOverlays(this)) {
      overlayPermissionLauncher.launch(
        Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION, Uri.parse("package:$packageName")),
      )
      return
    }
    val manager = getSystemService(MediaProjectionManager::class.java)
    screenCaptureLauncher.launch(manager.createScreenCaptureIntent())
  }
}
