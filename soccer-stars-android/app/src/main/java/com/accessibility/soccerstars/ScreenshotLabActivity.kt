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
    private var beforeBitmap: Bitmap? = null
    private var afterBitmap: Bitmap? = null
    private var controller: AssistController? = null
    private var pickingBefore = true

    private val pickImage = registerForActivityResult(
        ActivityResultContracts.GetContent(),
    ) { uri ->
        if (uri == null) return@registerForActivityResult
        loadImage(uri, pickingBefore)
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

        binding.pickBeforeButton.setOnClickListener {
            pickingBefore = true
            pickImage.launch("image/*")
        }
        binding.pickAfterButton.setOnClickListener {
            pickingBefore = false
            pickImage.launch("image/*")
        }
        binding.analyzePairButton.setOnClickListener { analyzePair() }
        binding.analyzeButton.setOnClickListener { analyzeSingle() }

        updateButtons()
        intent?.data?.let { loadImage(it, true) }
    }

    private fun loadImage(uri: Uri, isBefore: Boolean) {
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

            if (isBefore) {
                beforeBitmap?.recycle()
                beforeBitmap = bitmap
                binding.labPreview.setImage(bitmap)
                binding.resultText.text = getString(R.string.lab_before_loaded)
            } else {
                afterBitmap?.recycle()
                afterBitmap = bitmap
                binding.resultText.text = getString(R.string.lab_after_loaded)
            }

            updateButtons()
            if (beforeBitmap != null && afterBitmap != null) {
                binding.resultText.text = getString(R.string.lab_both_loaded)
            }
        }
    }

    private fun updateButtons() {
        binding.analyzeButton.isEnabled = beforeBitmap != null
        binding.analyzePairButton.isEnabled = beforeBitmap != null && afterBitmap != null
    }

    private fun analyzeSingle() {
        val bitmap = beforeBitmap ?: return
        val ctrl = controller ?: return
        binding.analyzeButton.isEnabled = false
        binding.analyzePairButton.isEnabled = false
        binding.resultText.text = getString(R.string.lab_analyzing)

        lifecycleScope.launch {
            val result = withContext(Dispatchers.Default) {
                ctrl.analyzeFrame(bitmap)
            }
            binding.labPreview.setAnalysis(result)
            binding.resultText.text = formatSingleResult(result)
            updateButtons()
        }
    }

    private fun analyzePair() {
        val before = beforeBitmap ?: return
        val after = afterBitmap ?: return
        val ctrl = controller ?: return
        binding.analyzeButton.isEnabled = false
        binding.analyzePairButton.isEnabled = false
        binding.resultText.text = getString(R.string.lab_analyzing)

        lifecycleScope.launch {
            val pairState = withContext(Dispatchers.Default) {
                ctrl.analyzePair(before, after)
            }
            binding.labPreview.setImage(before)
            binding.labPreview.setPairAnalysis(pairState.result)
            binding.labPreview.setAnalysis(pairState.overlay)
            binding.resultText.text = pairState.report
            updateButtons()
        }
    }

    private fun formatSingleResult(state: OverlayState): String = buildString {
        appendLine("═══ گزارش تحلیل تک‌عکس ═══")
        appendLine()
        appendLine("وضعیت: ${sceneLabel(state.scenePhase)}")
        appendLine("دقت تشخیص: ${(state.confidence * 100).toInt()}%")
        appendLine("مهره آبی: ${state.bluePuckCount} · مهره قرمز: ${state.redPuckCount}")
        if (state.mapFamily != null) {
            appendLine("نوع مپ: ${state.mapFamily}")
        }
        appendLine()
        appendLine("پیام: ${state.statusText}")
        appendLine()
        if (state.finalBallPoint != null) {
            appendLine("🎯 مقصد نهایی توپ:")
            appendLine("   X = ${state.finalBallPoint!!.x.toInt()}")
            appendLine("   Y = ${state.finalBallPoint!!.y.toInt()}")
            if (state.goalScored) appendLine("   ✅ احتمال گل")
        } else if (state.active) {
            appendLine("مسیر توپ محاسبه شد (${state.ballPath.size} نقطه)")
        } else {
            appendLine("💡 برای دقت بیشتر، عکس «بعد شلیک» را هم اضافه کنید")
        }
        if (state.analysisNotes.isNotEmpty()) {
            appendLine()
            appendLine("جزئیات:")
            state.analysisNotes.forEach { appendLine("• $it") }
        }
    }

    private fun sceneLabel(phase: ScenePhase): String = when (phase) {
        ScenePhase.IN_MATCH -> "مسابقه ✓"
        ScenePhase.MENU_OR_HOME -> "منو/صفحه اصلی"
        ScenePhase.UNKNOWN -> "نامشخص"
    }

    override fun onDestroy() {
        beforeBitmap?.recycle()
        afterBitmap?.recycle()
        beforeBitmap = null
        afterBitmap = null
        super.onDestroy()
    }
}
