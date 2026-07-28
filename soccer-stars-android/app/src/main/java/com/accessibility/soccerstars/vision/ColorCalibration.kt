package com.accessibility.soccerstars.vision

/**
 * HSV thresholds calibrated from Soccer Stars APK assets:
 * BrazilField-hd, EnglandField-hd, ball0-hd, standard_blue_puck-hd, Confettis_Yellow.
 */
object ColorCalibration {
    // BrazilField median H≈91°, S≈0.96
    const val FIELD_GREEN_H_MIN = 58f
    const val FIELD_GREEN_H_MAX = 135f
    const val FIELD_S_MIN = 0.20f
    const val FIELD_V_MIN = 0.18f

    // EnglandField-hd: yellow-brown turf H≈42–55°
    const val FIELD_YELLOW_H_MIN = 24f
    const val FIELD_YELLOW_H_MAX = 78f
    const val FIELD_YELLOW_S_MIN = 0.10f
    const val FIELD_YELLOW_V_MIN = 0.20f

    // Legacy alias
    const val FIELD_H_MIN = FIELD_GREEN_H_MIN
    const val FIELD_H_MAX = FIELD_GREEN_H_MAX

    // ball0-hd: grayscale, V median 1.0, S≈0
    const val BALL_S_MAX = 0.32f
    const val BALL_V_MIN = 0.68f

    // standard_blue_puck center H≈208°
    const val BLUE_H_MIN = 188f
    const val BLUE_H_MAX = 245f
    const val BLUE_S_MIN = 0.32f
    const val BLUE_V_MIN = 0.30f

    // red team tint
    const val RED_H_MAX = 24f
    const val RED_H_WRAP_MIN = 328f
    const val RED_S_MIN = 0.35f
    const val RED_V_MIN = 0.25f

    // silver/gold puck rim (minigames)
    const val METAL_S_MAX = 0.28f
    const val METAL_V_MIN = 0.55f

    // yellow aim guide (standard match)
    const val YELLOW_H_MIN = 12f
    const val YELLOW_H_MAX = 58f
    const val YELLOW_S_MIN = 0.28f
    const val YELLOW_V_MIN = 0.35f

    // orange aim arrow (Lucky Shot and minigames)
    const val ORANGE_H_MIN = 4f
    const val ORANGE_H_MAX = 40f
    const val ORANGE_S_MIN = 0.32f
    const val ORANGE_V_MIN = 0.32f

    // Match scene: center crop turf ratio
    const val MATCH_CENTER_FIELD_MIN = 0.14f
    const val MENU_CENTER_FIELD_MAX = 0.08f

    // Valid play-field geometry (portrait + landscape)
    const val FIELD_MIN_HEIGHT_RATIO = 0.28f
    const val FIELD_MIN_AREA_RATIO = 0.14f
    const val FIELD_ASPECT_MIN = 0.50f
    const val FIELD_ASPECT_MAX = 2.60f

    // Legacy aliases
    const val MATCH_CENTER_GREEN_MIN = MATCH_CENTER_FIELD_MIN
    const val MENU_CENTER_GREEN_MAX = MENU_CENTER_FIELD_MAX
}
