#pragma once

#include <cstdint>

struct HookDiagnostics {
    int classes_scanned = 0;
    int hooks_patched = 0;
    int watch_methods_found = 0;
    int hook_events = 0;
    bool libgame_loaded = false;
    bool hooks_installed = false;
    bool class_table_ok = false;
    bool touch_hooks = false;
    char report[640] = {};
};

void installGameHooks();
void pollGameHooks();
bool gameHooksInstalled();
int gameClassesScanned();
int gameWatchMethodsFound();
void forceRescanHooks();
void runHookDiagnostics(HookDiagnostics& out);
