package com.accessibility.soccerstars

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class AccessibilityHelperTest {
    private val pkg = "com.accessibility.soccerstars"
    private val className = "com.accessibility.soccerstars.AssistAccessibilityService"
    private val fullId = "$pkg/$className"

    @Test
    fun matchesFullComponentInSecureString() {
        val raw = "com.other/.OtherService:$fullId"
        assertTrue(AccessibilityHelper.matchesComponent(raw, pkg, className))
    }

    @Test
    fun matchesMiuiStyleEntry() {
        assertTrue(AccessibilityHelper.idMatchesComponent(fullId, pkg, className))
    }

    @Test
    fun matchesByPackageAndClassSubstring() {
        val raw = "com.accessibility.soccerstars/.AssistAccessibilityService"
        assertTrue(AccessibilityHelper.idMatchesComponent(raw, pkg, className))
    }

    @Test
    fun rejectsUnrelatedService() {
        val raw = "com.example/.ExampleService"
        assertFalse(AccessibilityHelper.idMatchesComponent(raw, pkg, className))
    }

    @Test
    fun rejectsEmptySecureString() {
        assertFalse(AccessibilityHelper.matchesComponent(null, pkg, className))
        assertFalse(AccessibilityHelper.matchesComponent("", pkg, className))
    }
}
