package com.accessibility.soccerstars

import android.os.Bundle
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import com.accessibility.soccerstars.databinding.ActivitySettingsBinding
import com.accessibility.soccerstars.physics.PhysicsStorage
import com.google.android.material.slider.Slider

class SettingsActivity : AppCompatActivity() {
    private lateinit var binding: ActivitySettingsBinding

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySettingsBinding.inflate(layoutInflater)
        setContentView(binding.root)

        val ruler = AppPreferences.rulerExtension(this).toFloat()
        val scale = AppPreferences.processScale(this)

        binding.rulerSlider.value = ruler
        binding.scaleSlider.value = scale
        binding.showPuckSwitch.isChecked = AppPreferences.showPuckPath(this)
        binding.showEnemySwitch.isChecked = AppPreferences.showEnemyPaths(this)
        binding.showLegendSwitch.isChecked = AppPreferences.showLegend(this)
        binding.showDebugSwitch.isChecked = AppPreferences.showDebug(this)
        binding.physicsPathText.text = PhysicsStorage.displayPath(this)

        updateLabels(ruler, scale)

        binding.rulerSlider.addOnChangeListener { _: Slider, value, _ ->
            updateLabels(value, binding.scaleSlider.value)
        }
        binding.scaleSlider.addOnChangeListener { _: Slider, value, _ ->
            updateLabels(binding.rulerSlider.value, value)
        }

        binding.saveButton.setOnClickListener {
            AppPreferences.save(
                context = this,
                rulerExtension = binding.rulerSlider.value,
                showPuckPath = binding.showPuckSwitch.isChecked,
                showEnemyPaths = binding.showEnemySwitch.isChecked,
                showLegend = binding.showLegendSwitch.isChecked,
                processScale = binding.scaleSlider.value,
                showDebug = binding.showDebugSwitch.isChecked,
            )
            Toast.makeText(this, R.string.settings_saved, Toast.LENGTH_SHORT).show()
            finish()
        }
    }

    private fun updateLabels(ruler: Float, scale: Float) {
        binding.rulerValueText.text = "طول خط‌کش: ${ruler.toInt()} پیکسل"
        binding.scaleValueText.text = "کیفیت تشخیص: ${(scale * 100).toInt()}%"
    }
}
