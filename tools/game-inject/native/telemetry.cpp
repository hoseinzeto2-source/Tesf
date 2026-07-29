#include "telemetry.h"

#include <dlfcn.h>
#include <algorithm>
#include <cmath>
#include <cstdio>
#include <cstring>
#include <vector>

static const char* kGameLib = "libgame-SSM-GooglePlay-Gold-Release-Module-1013.so";

static void* gameHandle() {
    return dlopen(kGameLib, RTLD_NOLOAD);
}

bool isGameLibLoaded() {
    return gameHandle() != nullptr;
}

GameExports readGameExports() {
    GameExports out;
    void* handle = gameHandle();
    if (!handle) return out;

    out.lib_loaded = true;

    if (auto sym = (float*)dlsym(handle, "sInternalVelocity")) {
        out.internal_velocity = *sym;
    }
    if (auto sym = (uint8_t*)dlsym(handle, "sPhysicsDebugEnabled")) {
        out.physics_debug = *sym;
    }
    if (auto sym = (uint8_t*)dlsym(handle, "sPhysicsDebugDiagnostics")) {
        out.physics_diag = *sym;
    }
    return out;
}

static bool isOnPitch(float x, float y) {
    return x > 0.03f && x < 0.97f && y > 0.06f && y < 0.94f;
}

std::vector<BodySample> scanBodiesLimited(int maxBodies, size_t maxBytes) {
    std::vector<BodySample> out;
    if (!isGameLibLoaded()) return out;

    size_t budget = maxBytes;
    FILE* f = fopen("/proc/self/maps", "r");
    if (!f) return out;

    char line[512];
    while (fgets(line, sizeof(line), f) && budget > 0) {
        unsigned long start = 0, end = 0;
        char perms[8] = {};
        if (sscanf(line, "%lx-%lx %4s", &start, &end, perms) < 3) continue;
        if (perms[0] != 'r' || perms[1] != 'w') continue;

        size_t len = end - start;
        if (len < 64 || len > 2 * 1024 * 1024) continue;

        size_t scan_len = std::min(len, budget);
        budget -= scan_len;

        auto* base = reinterpret_cast<uint8_t*>(start);
        for (size_t off = 0; off + 16 < scan_len; off += 8) {
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

    int ballIdx = -1;
    float bestScore = -1.f;
    for (int i = 0; i < (int)out.size(); i++) {
        float dist = std::hypot(out[i].x - 0.5f, out[i].y - 0.5f);
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

static void scanScoreHeuristic(MatchSnapshot& snap) {
    snap.score_home = -1.f;
    snap.score_away = -1.f;
    if (!snap.exports.lib_loaded) return;

    size_t budget = 128 * 1024;
    FILE* f = fopen("/proc/self/maps", "r");
    if (!f) return;

    char line[512];
    while (fgets(line, sizeof(line), f) && budget > 0) {
        unsigned long start = 0, end = 0;
        char perms[8] = {};
        if (sscanf(line, "%lx-%lx %4s", &start, &end, perms) < 3) continue;
        if (perms[0] != 'r' || perms[1] != 'w') continue;
        size_t len = end - start;
        if (len < 32 || len > 256 * 1024) continue;

        size_t scan_len = std::min(len, budget);
        budget -= scan_len;

        auto* base = reinterpret_cast<uint8_t*>(start);
        for (size_t off = 0; off + 8 < scan_len; off += 8) {
            int32_t a, b;
            memcpy(&a, base + off, 4);
            memcpy(&b, base + off + 4, 4);
            if (a >= 0 && a <= 20 && b >= 0 && b <= 20 && a + b <= 25) {
                snap.score_home = (float)a;
                snap.score_away = (float)b;
                fclose(f);
                return;
            }
        }
    }
    fclose(f);
}

MatchSnapshot buildMatchSnapshot(int displayW, int displayH, bool eglHooked, int swapFrames,
                                 int framesSinceLib, bool allowBodyScan) {
    MatchSnapshot snap;
    snap.display_w = displayW;
    snap.display_h = displayH;
    snap.swap_frames = swapFrames;
    snap.egl_hooked = eglHooked;
    snap.frames_since_lib = framesSinceLib;
    snap.exports = readGameExports();
    snap.live_scan_active = allowBodyScan && snap.exports.lib_loaded;

    if (!snap.live_scan_active) return snap;

    auto bodies = scanBodiesLimited(14, 512 * 1024);
    snap.body_count = (int)bodies.size();
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
