#include "telemetry.h"

#include <dlfcn.h>
#include <algorithm>
#include <cmath>
#include <cstdio>
#include <cstring>
#include <vector>

static const char* kGameLib = "libgame-SSM-GooglePlay-Gold-Release-Module-1013.so";

static bool isOnPitch(float x, float y) {
    return x > 0.03f && x < 0.97f && y > 0.06f && y < 0.94f;
}

std::vector<BodySample> scanBodies(int maxBodies) {
    std::vector<BodySample> out;
    FILE* f = fopen("/proc/self/maps", "r");
    if (!f) return out;
    char line[512];
    while (fgets(line, sizeof(line), f)) {
        unsigned long start = 0, end = 0;
        char perms[8] = {};
        if (sscanf(line, "%lx-%lx %4s", &start, &end, perms) < 3) continue;
        if (perms[0] != 'r' || perms[1] != 'w') continue;
        size_t len = end - start;
        if (len < 64 || len > 8 * 1024 * 1024) continue;

        auto* base = reinterpret_cast<uint8_t*>(start);
        for (size_t off = 0; off + 16 < len; off += 4) {
            float x, y, vx, vy;
            memcpy(&x, base + off, 4);
            memcpy(&y, base + off + 4, 4);
            if (!isOnPitch(x, y)) continue;
            memcpy(&vx, base + off + 8, 4);
            memcpy(&vy, base + off + 12, 4);
            float speed = std::sqrt(vx * vx + vy * vy);
            if (speed > 8.0f) continue;

            bool dup = false;
            for (const auto& b : out) {
                if (std::fabs(b.x - x) < 0.025f && std::fabs(b.y - y) < 0.025f) {
                    dup = true;
                    break;
                }
            }
            if (!dup) {
                out.push_back({x, y, vx, vy, speed, 0});
                if ((int)out.size() >= maxBodies) break;
            }
        }
        if ((int)out.size() >= maxBodies) break;
    }
    fclose(f);

    // Ball heuristic: highest speed during play, else closest to field center
    int ballIdx = -1;
    float bestScore = -1.f;
    for (int i = 0; i < (int)out.size(); i++) {
        float cx = 0.5f, cy = 0.5f;
        float dist = std::hypot(out[i].x - cx, out[i].y - cy);
        float score = out[i].speed * 2.0f + (1.0f - dist);
        if (score > bestScore) {
            bestScore = score;
            ballIdx = i;
        }
    }
    if (ballIdx >= 0) out[ballIdx].label = 1;
    for (int i = 0; i < (int)out.size(); i++) {
        if (i != ballIdx) out[i].label = 0;
    }
    return out;
}

static void* gameHandle() {
    void* handle = dlopen(kGameLib, RTLD_NOLOAD);
    if (!handle) handle = dlopen(kGameLib, RTLD_NOW);
    return handle;
}

float readInternalVelocity() {
    void* handle = gameHandle();
    if (!handle) return 0.f;
    auto sym = (float*)dlsym(handle, "sInternalVelocity");
    if (!sym) return 0.f;
    return *sym;
}

int readPhysicsDebug() {
    void* handle = dlopen(kGameLib, RTLD_NOLOAD);
    if (!handle) return 0;
    auto sym = (uint8_t*)dlsym(handle, "sPhysicsDebugEnabled");
    if (!sym) return 0;
    return *sym;
}

void enablePhysicsDebug() {
    void* handle = gameHandle();
    if (!handle) return;
    auto sym = (uint8_t*)dlsym(handle, "sPhysicsDebugEnabled");
    if (sym) *sym = 1;
}

static void scanScoreHeuristic(MatchSnapshot& snap) {
    snap.score_home = -1.f;
    snap.score_away = -1.f;
    FILE* f = fopen("/proc/self/maps", "r");
    if (!f) return;
    char line[512];
    while (fgets(line, sizeof(line), f)) {
        unsigned long start = 0, end = 0;
        char perms[8] = {};
        if (sscanf(line, "%lx-%lx %4s", &start, &end, perms) < 3) continue;
        if (perms[0] != 'r' || perms[1] != 'w') continue;
        size_t len = end - start;
        if (len < 32 || len > 512 * 1024) continue;
        auto* base = reinterpret_cast<uint8_t*>(start);
        for (size_t off = 0; off + 8 < len; off += 4) {
            int32_t a, b;
            memcpy(&a, base + off, 4);
            memcpy(&b, base + off + 4, 4);
            if (a >= 0 && a <= 20 && b >= 0 && b <= 20 && a + b <= 25) {
                if (snap.score_home < 0) {
                    snap.score_home = (float)a;
                    snap.score_away = (float)b;
                }
            }
        }
    }
    fclose(f);
}

MatchSnapshot buildMatchSnapshot(int displayW, int displayH, bool choreoHook, bool eglHook, int swapFrames) {
    MatchSnapshot snap;
    snap.display_w = displayW;
    snap.display_h = displayH;
    snap.choreographer_hooked = choreoHook;
    snap.egl_hooked = eglHook;
    snap.swap_frames = swapFrames;
    snap.internal_velocity = readInternalVelocity();
    snap.physics_debug = readPhysicsDebug();

    auto bodies = scanBodies(16);
    snap.body_count = (int)bodies.size();
    snap.puck_estimate = 0;
    for (const auto& b : bodies) {
        if (b.label == 1) {
            snap.ball_valid = true;
            snap.ball_x = b.x;
            snap.ball_y = b.y;
            snap.ball_vx = b.vx;
            snap.ball_vy = b.vy;
        } else {
            snap.puck_estimate++;
        }
    }
    scanScoreHeuristic(snap);
    return snap;
}
