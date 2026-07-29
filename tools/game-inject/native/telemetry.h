#pragma once

#include <cstdint>

struct GameExports {
    bool lib_loaded = false;
    float internal_velocity = 0.f;
    int physics_debug = 0;
    int physics_diag = 0;
};

enum class DataSource : uint8_t {
    None = 0,
    PhysicsExports = 1,
    HookShotOutcome = 2,
    HookGameStarted = 3,
    HookNetworkReq = 4,
    HookShotTaken = 5,
};

struct MatchSnapshot {
    int display_w = 0;
    int display_h = 0;
    int swap_frames = 0;
    int update_tick = 0;
    int hook_events = 0;
    int hooks_patched = 0;
    bool egl_hooked = false;
    bool hooks_installed = false;
    int frames_since_lib = 0;
    DataSource data_source = DataSource::None;
    char last_hook_sel[48] = {};

    GameExports exports;
    int body_count = 0;
    int puck_estimate = 0;
    float ball_x = 0.f;
    float ball_y = 0.f;
    bool ball_valid = false;
    float score_home = -1.f;
    float score_away = -1.f;
    float shot_angle = -1.f;
    float shot_power = -1.f;
};

bool isGameLibLoaded();
void resetLiveScanState();
GameExports readGameExportsCached();
bool parseShotOutcomeObject(const void* obj, MatchSnapshot& out);
bool parseGameStartedObject(const void* obj, MatchSnapshot& out);
bool parseShotTakenObject(const void* obj, MatchSnapshot& out);
bool parseNetworkRequest(const void* req, MatchSnapshot& out);
void commitHookSnapshot(const MatchSnapshot& snap, const char* sel_name);
void mergeHookSnapshot(MatchSnapshot& dst);
int getHookPatchedCount();
void telemetrySetHookPatchedCount(int n);
