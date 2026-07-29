package com.accessibility.soccerstars

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class ForegroundAppTrackerTest {
    @Test
    fun soccerStarsPackageMatchesVariants() {
        assertTrue(ForegroundAppTracker.isSoccerStarsPackage("com.miniclip.soccerstars"))
        assertTrue(ForegroundAppTracker.isSoccerStarsPackage("com.miniclip.soccerstars2"))
        assertFalse(ForegroundAppTracker.isSoccerStarsPackage(ForegroundAppTracker.OWN_PACKAGE))
        assertFalse(ForegroundAppTracker.isSoccerStarsPackage("com.android.chrome"))
    }
}
