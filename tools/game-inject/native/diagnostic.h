#pragma once

#include "telemetry.h"
#include "game_hooks.h"

#include <cstddef>

void diagnosticSetJavaVm(void* vm);

// Build full JSON diagnostic (hooks, classes, snapshot, logs). Returns bytes written (excl. null).
size_t buildDiagnosticJson(char* out, size_t cap, const MatchSnapshot& snap, const HookDiagnostics& diag);

// Write JSON to /sdcard/Download/ssm_research_dump.json (and app files dir). Returns path or error string.
const char* writeDiagnosticFile(const char* json, size_t len);
