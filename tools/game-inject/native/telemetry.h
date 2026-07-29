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
};

struct MatchSnapshot {
    int display_w = 0;
    int display_h = 0;
    int swap_frames = 0;
    int update_tick = 0;
    int hook_events = 0;
    bool egl_hooked = false;
    bool hooks_installed = false;
    int frames_since_lib = 0;
    DataSource data_source = DataSource::None;

    GameExports exports;
    int body_count = 0;
    int puck_estimate = 0;
    float ball_x = 0.f;
    float ball_y = 0.f;
    bool ball_valid = false;
    float score_home = -1.f;
    float score_away = -1.f;
};

bool isGameLibLoaded();
void resetLiveScanState();
GameExports readGameExportsCached();
bool parseShotOutcomeObject(const void* obj, MatchSnapshot& out);
bool parseGameStartedObject(const void* obj, MatchSnapshot& out);
void commitHookSnapshot(const MatchSnapshot& snap);
void mergeHookSnapshot(MatchSnapshot& dst);
