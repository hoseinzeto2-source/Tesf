#pragma once

#include "telemetry.h"

// GameGuardian-style in-process heap scan (read-only).
// Finds pitch XY clusters + score int pairs near field_state layouts.
bool heapScanMatch(MatchSnapshot& out);
