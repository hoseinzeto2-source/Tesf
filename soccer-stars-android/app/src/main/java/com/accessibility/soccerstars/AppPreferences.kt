package com.accessibility.soccerstars

import android.content.Context
import android.content.SharedPreferences
import com.accessibility.soccerstars.physics.PhysicsStorage

object AppPreferences {
    private const val PREFS = "soccer_stars_assist_prefs"
    private const val KEY_RULER = "ruler_extension"
    private const val KEY_SHOW_PUCK = "show_puck_path"
    private const val KEY_SHOW_ENEMY = "show_enemy_paths"
    private const val KEY_SHOW_LEGEND = "show_legend"
    private const val KEY_PROCESS_SCALE = "process_scale"
    private const val KEY_SHOW_DEBUG = "show_debug"
    private const val KEY_ASSIST_ENABLED = "assist_enabled"
    private const val KEY_HUD_X = "hud_x"
    private const val KEY_HUD_Y = "hud_y"

    fun prefs(context: Context): SharedPreferences =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    fun rulerExtension(context: Context): Double =
        prefs(context).getFloat(KEY_RULER, 380f).toDouble()

    fun showPuckPath(context: Context): Boolean =
        prefs(context).getBoolean(KEY_SHOW_PUCK, true)

    fun showEnemyPaths(context: Context): Boolean =
        prefs(context).getBoolean(KEY_SHOW_ENEMY, true)

    fun showLegend(context: Context): Boolean =
        prefs(context).getBoolean(KEY_SHOW_LEGEND, true)

    fun processScale(context: Context): Float =
        prefs(context).getFloat(KEY_PROCESS_SCALE, 0.85f).coerceIn(0.25f, 1.0f)

    fun showDebug(context: Context): Boolean =
        prefs(context).getBoolean(KEY_SHOW_DEBUG, true)

    fun assistEnabled(context: Context): Boolean =
        prefs(context).getBoolean(KEY_ASSIST_ENABLED, true)

    fun hudPosition(context: Context): Pair<Int, Int> {
        val p = prefs(context)
        return p.getInt(KEY_HUD_X, 24) to p.getInt(KEY_HUD_Y, 160)
    }

    fun setAssistEnabled(context: Context, enabled: Boolean) {
        prefs(context).edit().putBoolean(KEY_ASSIST_ENABLED, enabled).apply()
    }

    fun setShowDebug(context: Context, enabled: Boolean) {
        prefs(context).edit().putBoolean(KEY_SHOW_DEBUG, enabled).apply()
    }

    fun setHudPosition(context: Context, x: Int, y: Int) {
        prefs(context).edit().putInt(KEY_HUD_X, x).putInt(KEY_HUD_Y, y).apply()
    }

    fun save(
        context: Context,
        rulerExtension: Float,
        showPuckPath: Boolean,
        showEnemyPaths: Boolean,
        showLegend: Boolean,
        processScale: Float,
        showDebug: Boolean = showDebug(context),
    ) {
        prefs(context).edit()
            .putFloat(KEY_RULER, rulerExtension)
            .putBoolean(KEY_SHOW_PUCK, showPuckPath)
            .putBoolean(KEY_SHOW_ENEMY, showEnemyPaths)
            .putBoolean(KEY_SHOW_LEGEND, showLegend)
            .putFloat(KEY_PROCESS_SCALE, processScale)
            .putBoolean(KEY_SHOW_DEBUG, showDebug)
            .apply()
    }

    fun physicsConfigPath(context: Context): String =
        PhysicsStorage.physicsFile(context).absolutePath
}
