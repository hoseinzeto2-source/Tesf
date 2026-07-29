#pragma once
#include <cstdint>
#include <vector>

struct BodySample {
    float x, y, vx, vy, speed;
    int label; // 0=puck, 1=ball estimate, 2=other
};

struct MatchSnapshot {
    int frame_counter = 0;
    int body_count = 0;
    int puck_estimate = 0;
    float ball_x = 0.f;
    float ball_y = 0.f;
    float ball_vx = 0.f;
    float ball_vy = 0.f;
    bool ball_valid = false;
    float score_home = -1.f;
    float score_away = -1.f;
    float internal_velocity = 0.f;
    int physics_debug = 0;
    int display_w = 0;
    int display_h = 0;
    bool choreographer_hooked = false;
    bool egl_hooked = false;
    int swap_frames = 0;
};

std::vector<BodySample> scanBodies(int maxBodies);
float readInternalVelocity();
int readPhysicsDebug();
void enablePhysicsDebug();
MatchSnapshot buildMatchSnapshot(int displayW, int displayH, bool choreoHook, bool eglHook, int swapFrames);
