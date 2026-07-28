package com.accessibility.soccerstars

import android.os.Bundle
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import com.accessibility.soccerstars.databinding.ActivitySettingsBinding

class SettingsActivity : AppCompatActivity() {
  private lateinit var binding: ActivitySettingsBinding

  override fun onCreate(savedInstanceState: Bundle?) {
    super.onCreate(savedInstanceState)
    binding = ActivitySettingsBinding.inflate(layoutInflater)
    setContentView(binding.root)

    binding.rulerSlider.value = AppPreferences.rulerExtension(this).toFloat()
    binding.scaleSlider.value = AppPreferences.processScale(this)
    binding.showPuckSwitch.isChecked = AppPreferences.showPuckPath(this)
    binding.showLegendSwitch.isChecked = AppPreferences.showLegend(this)

    binding.saveButton.setOnClickListener {
      AppPreferences.save(
        context = this,
        rulerExtension = binding.rulerSlider.value,
        showPuckPath = binding.showPuckSwitch.isChecked,
        showLegend = binding.showLegendSwitch.isChecked,
        processScale = binding.scaleSlider.value,
      )
      Toast.makeText(this, "ذخیره شد — برای اعمال کامل سرویس را دوباره شروع کنید", Toast.LENGTH_LONG).show()
      finish()
    }
  }
}
