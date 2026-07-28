package com.accessibility.soccerstars.vision

/**
 * HSV thresholds calibrated from Soccer Stars APK assets:
 * BrazilField-hd, EnglandField-hd, ball0-hd, standard_blue_puck-hd, Confettis_Yellow.
 */
object ColorCalibration {
    // BrazilField median H≈91°, S≈0.96; England H≈69°
    const val FIELD_H_MIN = 58f
    const val FIELD_H_MAX = 135f
    const val FIELD_S_MIN = 0.32f
    const val FIELD_V_MIN = 0.20f

    // ball0-hd: grayscale, V median 1.0, S≈0
    const val BALL_S_MAX = 0.30f
    const val BALL_V_MIN = 0.72f

    // standard_blue_puck center H≈208°
    const val BLUE_H_MIN = 188f
    const val BLUE_H_MAX = 245f
    const val BLUE_S_MIN = 0.38f
    const val BLUE_V_MIN = 0.35f

    // red team tint (runtime, no red puck PNG — menu red median H≈12°)
    const val RED_H_MAX = 22f
    const val RED_H_WRAP_MIN = 328f
    const val RED_S_MIN = 0.40f
    const val RED_V_MIN = 0.28f

    // yellow aim guide (standard match)
    const val YELLOW_H_MIN = 14f
    const val YELLOW_H_MAX = 55f
    const val YELLOW_S_MIN = 0.30f
    const val YELLOW_V_MIN = 0.40f

    // orange aim arrow (Lucky Shot and minigames)
    const val ORANGE_H_MIN = 4f
    const val ORANGE_H_MAX = 38f
    const val ORANGE_S_MIN = 0.38f
    const val ORANGE_V_MIN = 0.38f

    // Match scene: center crop green ratio (Brazil field texture ≈90%, main menu ≈5%)
    const val MATCH_CENTER_GREEN_MIN = 0.24f
    const val MENU_CENTER_GREEN_MAX = 0.14f

    // Valid play-field geometry (portrait match view; relaxed for minigames)
    const val FIELD_MIN_HEIGHT_RATIO = 0.32f
    const val FIELD_MIN_AREA_RATIO = 0.16f
    const val FIELD_ASPECT_MIN = 0.62f
    const val FIELD_ASPECT_MAX = 1.08f
}
