package com.accessibility.soccerstars

import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import android.os.Bundle
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.accessibility.soccerstars.databinding.ActivityScreenshotLabBinding
import com.accessibility.soccerstars.physics.PhysicsStorage
import com.accessibility.soccerstars.vision.ScenePhase
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class ScreenshotLabActivity : AppCompatActivity() {
    private lateinit var binding: ActivityScreenshotLabBinding
    private var sourceBitmap: Bitmap? = null
    private var controller: AssistController? = null

    private val pickImage = registerForActivityResult(
        ActivityResultContracts.GetContent(),
    ) { uri ->
        if (uri == null) return@registerForActivityResult
        loadImage(uri)
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityScreenshotLabBinding.inflate(layoutInflater)
        setContentView(binding.root)

        PhysicsStorage.ensureDefault(this)
        controller = AssistController(
            context = this,
            physicsPath = PhysicsStorage.physicsFile(this).absolutePath,
            rulerExtensionPx = AppPreferences.rulerExtension(this),
            showPuckPath = true,
            showEnemyPaths = true,
            showLegend = true,
        )

        binding.pickImageButton.setOnClickListener { pickImage.launch("image/*") }
        binding.analyzeButton.setOnClickListener { analyzeCurrentImage() }

        intent?.data?.let { loadImage(it) }
    }

    private fun loadImage(uri: Uri) {
        lifecycleScope.launch {
            val bitmap = withContext(Dispatchers.IO) {
                contentResolver.openInputStream(uri)?.use { stream ->
                    BitmapFactory.decodeStream(stream)
                }
            }
            if (bitmap == null) {
                Toast.makeText(this@ScreenshotLabActivity, R.string.lab_load_failed, Toast.LENGTH_SHORT).show()
                return@launch
            }
            sourceBitmap?.recycle()
            sourceBitmap = bitmap
            binding.labPreview.setImage(bitmap)
            binding.analyzeButton.isEnabled = true
            binding.resultText.text = getString(R.string.lab_image_loaded)
        }
    }

    private fun analyzeCurrentImage() {
        val bitmap = sourceBitmap ?: return
        val ctrl = controller ?: return
        binding.analyzeButton.isEnabled = false
        binding.resultText.text = getString(R.string.lab_analyzing)

        lifecycleScope.launch {
            val result = withContext(Dispatchers.Default) {
                ctrl.analyzeFrame(bitmap)
            }
            binding.labPreview.setAnalysis(result)
            binding.resultText.text = formatResult(result)
            binding.analyzeButton.isEnabled = true
        }
    }

    private fun formatResult(state: OverlayState): String = buildString {
        appendLine("═══ گزارش تحلیل هوش مصنوعی ═══")
        appendLine()
        appendLine("وضعیت: ${sceneLabel(state.scenePhase)}")
        appendLine("دقت تشخیص: ${(state.confidence * 100).toInt()}%")
        appendLine("مهره آبی: ${state.bluePuckCount} · مهره قرمز: ${state.redPuckCount}")
        if (state.mapFamily != null) {
            appendLine("نوع مپ: ${state.mapFamily}")
        }
        appendLine()
        appendLine("پیام: ${state.statusText}")
        if (state.analysisNotes.any { it.contains("بعد از شلیک") }) {
            appendLine()
            appendLine("── تحلیل بعد از شلیک ──")
            state.analysisNotes.filter { it.contains("سرعت") || it.contains("بعد از شلیک") || it.contains("حرکت") }
                .forEach { appendLine(it) }
        }
        appendLine()
        if (state.finalBallPoint != null) {
            appendLine("🎯 مقصد نهایی توپ:")
            appendLine("   X = ${state.finalBallPoint!!.x.toInt()}")
            appendLine("   Y = ${state.finalBallPoint!!.y.toInt()}")
            if (state.goalScored) appendLine("   ✅ احتمال گل")
        } else if (state.active) {
            appendLine("مسیر توپ محاسبه شد (${state.ballPath.size} نقطه)")
        } else {
            appendLine("⚠️ شلیک فعال نیست — عکس باید هنگام کشیدن مهره باشد")
        }
        appendLine()
        if (state.enemyPuckPaths.isNotEmpty()) {
            appendLine("مسیر مهره‌های حریف: ${state.enemyPuckPaths.size} مهره")
        }
        if (state.analysisNotes.isNotEmpty()) {
            appendLine()
            appendLine("جزئیات:")
            state.analysisNotes.forEach { appendLine("• $it") }
        }
        appendLine()
        appendLine("راهنما: عکس‌های قبل/بعد شلیک را اینجا تست کنید تا تشخیص بهتر شود.")
    }

    private fun sceneLabel(phase: ScenePhase): String = when (phase) {
        ScenePhase.IN_MATCH -> "مسابقه ✓"
        ScenePhase.MENU_OR_HOME -> "منو/صفحه اصلی"
        ScenePhase.UNKNOWN -> "نامشخص"
    }

    override fun onDestroy() {
        sourceBitmap?.recycle()
        sourceBitmap = null
        super.onDestroy()
    }
}
