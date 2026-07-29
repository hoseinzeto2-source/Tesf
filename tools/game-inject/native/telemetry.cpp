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

struct MapRegion {
    uintptr_t start = 0;
    uintptr_t end = 0;
};

struct RepeatedPtrFieldView {
    void* arena = nullptr;
    int32_t current_size = 0;
    int32_t total_size = 0;
    void* rep = nullptr;
};

static SymbolCache g_syms;
static std::vector<MapRegion> g_rw_regions;
static size_t g_scan_region_idx = 0;
static size_t g_scan_region_off = 0;
static int g_scan_pass = 0;

static void* gameHandle() {
    return dlopen(kGameLib, RTLD_NOLOAD);
}

bool isGameLibLoaded() {
    return gameHandle() != nullptr;
}

void resetLiveScanState() {
    g_rw_regions.clear();
    g_scan_region_idx = 0;
    g_scan_region_off = 0;
    g_scan_pass = 0;
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
        if (len < 128 || len > 8 * 1024 * 1024) continue;
        g_rw_regions.push_back({start, end});
    }
    fclose(f);
}

static bool isValidPtr(const void* p) {
    uintptr_t v = (uintptr_t)p;
    return v > 0x10000 && v < 0x7fffffffffffULL;
}

static bool looksLikeRepeatedPtrField(const RepeatedPtrFieldView& r) {
    if (!isValidPtr(r.arena) || !isValidPtr(r.rep)) return false;
    if (r.current_size < 1 || r.current_size > 25) return false;
    if (r.total_size < r.current_size || r.total_size > 40) return false;
    return true;
}

static bool readRepeatedPtrField(const uint8_t* base, RepeatedPtrFieldView& out) {
    memcpy(&out, base, sizeof(RepeatedPtrFieldView));
    return looksLikeRepeatedPtrField(out);
}

static bool parsePuckPosition(void* puck_obj, float& x, float& y, int32_t& puck_id) {
    if (!isValidPtr(puck_obj)) return false;
    auto* p = reinterpret_cast<uint8_t*>(puck_obj);

    for (size_t off = 16; off < 96; off += 8) {
        void* pos_ptr = nullptr;
        memcpy(&pos_ptr, p + off, sizeof(void*));
        if (!isValidPtr(pos_ptr)) continue;

        double dx = 0, dy = 0;
        memcpy(&dx, pos_ptr, sizeof(double));
        memcpy(&dy, reinterpret_cast<uint8_t*>(pos_ptr) + 8, sizeof(double));
        if (dx < 0.02 || dx > 0.98 || dy < 0.05 || dy > 0.95) continue;

        x = (float)dx;
        y = (float)dy;

        size_t id_off = off + 8;
        if (id_off + 12 <= 96) {
            memcpy(&puck_id, p + id_off, sizeof(int32_t));
        }
        return true;
    }
    return false;
}

static void ingestFieldState(const RepeatedPtrFieldView& field, MatchSnapshot& snap) {
    if (!looksLikeRepeatedPtrField(field)) return;

    int32_t allocated = 0;
    void** elements = nullptr;
    memcpy(&allocated, field.rep, sizeof(int32_t));
    memcpy(&elements, reinterpret_cast<uint8_t*>(field.rep) + 8, sizeof(void**));
    if (!isValidPtr(elements)) return;

    snap.body_count = field.current_size;
    snap.puck_estimate = 0;
    snap.ball_valid = false;

    float best_ball_score = -1.f;
    int best_ball_idx = -1;

    struct PuckHit {
        float x, y;
        int32_t id;
    };
    std::vector<PuckHit> hits;

    for (int i = 0; i < field.current_size; i++) {
        void* puck_obj = nullptr;
        memcpy(&puck_obj, elements + i, sizeof(void*));
        if (!puck_obj) continue;

        float x = 0, y = 0;
        int32_t puck_id = -1;
        if (!parsePuckPosition(puck_obj, x, y, puck_id)) continue;

        hits.push_back({x, y, puck_id});

        float dist = std::hypot(x - 0.5f, y - 0.5f);
        float score = (puck_id == 0 ? 2.f : 0.f) + (1.f - dist);
        if (score > best_ball_score) {
            best_ball_score = score;
            best_ball_idx = (int)hits.size() - 1;
        }
    }

  if (best_ball_idx >= 0) {
        snap.ball_valid = true;
        snap.ball_x = hits[best_ball_idx].x;
        snap.ball_y = hits[best_ball_idx].y;
        snap.puck_estimate = (int)hits.size() - 1;
    } else {
        snap.puck_estimate = (int)hits.size();
    }
}

static bool tryParseShotOutcome(uint8_t* obj, size_t max_len, MatchSnapshot& snap) {
    if (max_len < 160) return false;

    for (size_t off = 0; off + 160 <= max_len; off += 8) {
        RepeatedPtrFieldView field_state{};
        RepeatedPtrFieldView goal_bonus{};
        if (!readRepeatedPtrField(obj + off, field_state)) continue;
        if (!readRepeatedPtrField(obj + off + 16, goal_bonus)) continue;

        for (size_t score_off = off + 32; score_off + 8 < off + 160; score_off += 4) {
            int32_t home = 0, away = 0;
            memcpy(&home, obj + score_off, sizeof(int32_t));
            memcpy(&away, obj + score_off + 4, sizeof(int32_t));
            if (home < 0 || home > 15 || away < 0 || away > 15) continue;
            if (home + away > 25) continue;

            snap.score_home = (float)home;
            snap.score_away = (float)away;
            ingestFieldState(field_state, snap);
            snap.data_source = DataSource::ProtobufShotOutcome;
            return true;
        }
    }
    return false;
}

void applyProtobufMatchScan(MatchSnapshot& snap, size_t bytesPerPass) {
    if (!snap.exports.lib_loaded) return;

    rebuildRegionList();
    if (g_rw_regions.empty()) return;

    size_t budget = bytesPerPass;
    int regionsVisited = 0;
    MatchSnapshot found;
    found.exports = snap.exports;

    while (budget > 0 && regionsVisited < g_rw_regions.size()) {
        MapRegion& region = g_rw_regions[g_scan_region_idx];
        size_t len = region.end - region.start;
        uint8_t* base = reinterpret_cast<uint8_t*>(region.start);

        while (g_scan_region_off + 160 < len && budget > 0) {
            size_t chunk = std::min(budget, len - g_scan_region_off);
            if (chunk >= 160) {
                if (tryParseShotOutcome(base + g_scan_region_off, chunk, found)) {
                    snap.score_home = found.score_home;
                    snap.score_away = found.score_away;
                    snap.ball_valid = found.ball_valid;
                    snap.ball_x = found.ball_x;
                    snap.ball_y = found.ball_y;
                    snap.body_count = found.body_count;
                    snap.puck_estimate = found.puck_estimate;
                    snap.data_source = DataSource::ProtobufShotOutcome;
                }
            }
            g_scan_region_off += 256;
            budget -= 256;
        }

        if (g_scan_region_off + 160 >= len) {
            g_scan_region_idx = (g_scan_region_idx + 1) % g_rw_regions.size();
            g_scan_region_off = 0;
            regionsVisited++;
            if (g_scan_region_idx == 0) g_scan_pass++;
        } else {
            break;
        }
    }

    snap.scan_pass = g_scan_pass;
}
