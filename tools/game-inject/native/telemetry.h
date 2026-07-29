#pragma once

#include <cstdint>
#include <vector>

struct BodySample {
    float x, y, vx, vy, speed;
    int label; // 0=puck, 1=ball estimate
};

struct GameExports {
    bool lib_loaded = false;
    float internal_velocity = 0.f;
    int physics_debug = 0;
    int physics_diag = 0;
};

struct MatchSnapshot {
    int display_w = 0;
    int display_h = 0;
    int swap_frames = 0;
    bool egl_hooked = false;
    bool live_scan_active = false;
    int frames_since_lib = 0;

    GameExports exports;
    int body_count = 0;
    int puck_estimate = 0;
    float ball_x = 0.f;
    float ball_y = 0.f;
    float ball_vx = 0.f;
    float ball_vy = 0.f;
    bool ball_valid = false;
    float score_home = -1.f;
    float score_away = -1.f;
};

bool isGameLibLoaded();
GameExports readGameExports();
std::vector<BodySample> scanBodiesLimited(int maxBodies, size_t maxBytes);
MatchSnapshot buildMatchSnapshot(int displayW, int displayH, bool eglHooked, int swapFrames,
                                   int framesSinceLib, bool allowBodyScan);
