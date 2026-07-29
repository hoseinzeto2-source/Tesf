#include "telemetry.h"

#include <dlfcn.h>
#include <algorithm>
#include <cmath>
#include <cstdio>
#include <cstring>
#include <vector>

static const char* kGameLib = "libgame-SSM-GooglePlay-Gold-Release-Module-1013.so";

struct SymbolCache {
    bool resolved = false;
    float* internal_velocity = nullptr;
    uint8_t* physics_debug = nullptr;
    uint8_t* physics_diag = nullptr;
};

static SymbolCache g_syms;

struct MapRegion {
    uintptr_t start = 0;
    uintptr_t end = 0;
};

static std::vector<MapRegion> g_rw_regions;
static size_t g_body_region_idx = 0;
static size_t g_body_region_off = 0;
static size_t g_score_region_idx = 0;
static size_t g_score_region_off = 0;
static int g_scan_pass = 0;

static void* gameHandle() {
    return dlopen(kGameLib, RTLD_NOLOAD);
}

bool isGameLibLoaded() {
    return gameHandle() != nullptr;
}

void resetLiveScanState() {
    g_rw_regions.clear();
    g_body_region_idx = 0;
    g_body_region_off = 0;
    g_score_region_idx = 0;
    g_score_region_off = 0;
    g_syms.resolved = false;
}

static void resolveSymbols() {
    if (g_syms.resolved) return;
    void* handle = gameHandle();
    if (!handle) return;
    g_syms.internal_velocity = (float*)dlsym(handle, "sInternalVelocity");
    g_syms.physics_debug = (uint8_t*)dlsym(handle, "sPhysicsDebugEnabled");
    g_syms.physics_diag = (uint8_t*)dlsym(handle, "sPhysicsDebugDiagnostics");
    g_syms.resolved = true;
}

GameExports readGameExportsCached() {
    GameExports out;
    if (!isGameLibLoaded()) return out;
    resolveSymbols();
    out.lib_loaded = true;
    if (g_syms.internal_velocity) out.internal_velocity = *g_syms.internal_velocity;
    if (g_syms.physics_debug) out.physics_debug = *g_syms.physics_debug;
    if (g_syms.physics_diag) out.physics_diag = *g_syms.physics_diag;
    return out;
}

static void rebuildRegionList() {
    if (!g_rw_regions.empty()) return;

    FILE* f = fopen("/proc/self/maps", "r");
    if (!f) return;

    char line[512];
    while (fgets(line, sizeof(line), f)) {
        unsigned long start = 0, end = 0;
        char perms[8] = {};
        if (sscanf(line, "%lx-%lx %4s", &start, &end, perms) < 3) continue;
        if (perms[0] != 'r' || perms[1] != 'w') continue;
        size_t len = end - start;
        if (len < 64 || len > 8 * 1024 * 1024) continue;
        g_rw_regions.push_back({start, end});
    }
    fclose(f);
}

static bool isOnPitch(float x, float y) {
    return x > 0.03f && x < 0.97f && y > 0.06f && y < 0.94f;
}

static void mergeBodies(std::vector<BodySample>& bodies, float x, float y, float vx, float vy, float speed,
                        int maxBodies) {
    for (const auto& b : bodies) {
        if (std::fabs(b.x - x) < 0.025f && std::fabs(b.y - y) < 0.025f) return;
    }
    if ((int)bodies.size() >= maxBodies) return;
    bodies.push_back({x, y, vx, vy, speed, 0});
}

static void labelBall(std::vector<BodySample>& bodies) {
    int ballIdx = -1;
    float bestScore = -1.f;
    for (int i = 0; i < (int)bodies.size(); i++) {
        float dist = std::hypot(bodies[i].x - 0.5f, bodies[i].y - 0.5f);
        float score = bodies[i].speed * 2.0f + (1.0f - dist);
        if (score > bestScore) {
            bestScore = score;
            ballIdx = i;
        }
    }
    for (int i = 0; i < (int)bodies.size(); i++) {
        bodies[i].label = (i == ballIdx) ? 1 : 0;
    }
}

void applyRotatingBodyScan(MatchSnapshot& snap, size_t bytesPerPass) {
    if (!snap.exports.lib_loaded) return;

    rebuildRegionList();
    if (g_rw_regions.empty()) return;

    std::vector<BodySample> bodies;
    if (snap.ball_valid) {
        bodies.push_back({snap.ball_x, snap.ball_y, snap.ball_vx, snap.ball_vy, 0.f, 1});
    }

    size_t budget = bytesPerPass;
    const int maxBodies = 16;
    int regionsVisited = 0;

    while (budget > 0 && regionsVisited < g_rw_regions.size()) {
        MapRegion& r = g_rw_regions[g_body_region_idx];
        uintptr_t base = r.start;
        size_t len = r.end - r.start;

        while (g_body_region_off + 16 < len && budget > 0) {
            auto* ptr = reinterpret_cast<uint8_t*>(base + g_body_region_off);
            float x, y, vx, vy;
            memcpy(&x, ptr, 4);
            memcpy(&y, ptr + 4, 4);
            if (isOnPitch(x, y)) {
                memcpy(&vx, ptr + 8, 4);
                memcpy(&vy, ptr + 12, 4);
                float speed = std::sqrt(vx * vx + vy * vy);
                if (speed <= 8.0f) mergeBodies(bodies, x, y, vx, vy, speed, maxBodies);
            }
            g_body_region_off += 8;
            budget -= 8;
        }

        if (g_body_region_off + 16 >= len) {
            g_body_region_idx = (g_body_region_idx + 1) % g_rw_regions.size();
            g_body_region_off = 0;
            regionsVisited++;
            if (g_body_region_idx == 0) g_scan_pass++;
        } else {
            break;
        }
    }

    labelBall(bodies);
    snap.scan_pass = g_scan_pass;
    snap.body_count = (int)bodies.size();
    snap.puck_estimate = 0;
    snap.ball_valid = false;

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
}

void applyRotatingScoreScan(MatchSnapshot& snap, size_t bytesPerPass) {
    if (!snap.exports.lib_loaded) return;

    rebuildRegionList();
    if (g_rw_regions.empty()) return;

    size_t budget = std::min(bytesPerPass, (size_t)256 * 1024);
    int regionsVisited = 0;

    while (budget > 0 && regionsVisited < g_rw_regions.size()) {
        MapRegion& r = g_rw_regions[g_score_region_idx];
        uintptr_t base = r.start;
        size_t len = r.end - r.start;

        while (g_score_region_off + 8 < len && budget > 0) {
            auto* ptr = reinterpret_cast<uint8_t*>(base + g_score_region_off);
            int32_t a, b;
            memcpy(&a, ptr, 4);
            memcpy(&b, ptr + 4, 4);
            if (a >= 0 && a <= 20 && b >= 0 && b <= 20 && a + b <= 25) {
                snap.score_home = (float)a;
                snap.score_away = (float)b;
                return;
            }
            g_score_region_off += 8;
            budget -= 8;
        }

        if (g_score_region_off + 8 >= len) {
            g_score_region_idx = (g_score_region_idx + 1) % g_rw_regions.size();
            g_score_region_off = 0;
            regionsVisited++;
        } else {
            break;
        }
    }
}
