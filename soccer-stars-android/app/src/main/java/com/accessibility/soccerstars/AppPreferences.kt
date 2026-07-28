package com.accessibility.soccerstars

import android.content.Context
import android.content.SharedPreferences
import com.accessibility.soccerstars.physics.PhysicsStorage

object AppPreferences {
    private const val PREFS = "soccer_stars_assist_prefs"
    private const val KEY_RULER = "ruler_extension"
    private const val KEY_SHOW_PUCK = "show_puck_path"
    private const val KEY_SHOW_LEGEND = "show_legend"
    private const val KEY_PROCESS_SCALE = "process_scale"

    fun prefs(context: Context): SharedPreferences =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    fun rulerExtension(context: Context): Double =
        prefs(context).getFloat(KEY_RULER, 280f).toDouble()

    fun showPuckPath(context: Context): Boolean =
        prefs(context).getBoolean(KEY_SHOW_PUCK, true)

    fun showLegend(context: Context): Boolean =
        prefs(context).getBoolean(KEY_SHOW_LEGEND, true)

    fun processScale(context: Context): Float =
        prefs(context).getFloat(KEY_PROCESS_SCALE, 0.45f).coerceIn(0.25f, 1.0f)

    fun save(
        context: Context,
        rulerExtension: Float,
        showPuckPath: Boolean,
        showLegend: Boolean,
        processScale: Float,
    ) {
        prefs(context).edit()
            .putFloat(KEY_RULER, rulerExtension)
            .putBoolean(KEY_SHOW_PUCK, showPuckPath)
            .putBoolean(KEY_SHOW_LEGEND, showLegend)
            .putFloat(KEY_PROCESS_SCALE, processScale)
            .apply()
    }

    fun physicsConfigPath(context: Context): String =
        PhysicsStorage.physicsFile(context).absolutePath
}
