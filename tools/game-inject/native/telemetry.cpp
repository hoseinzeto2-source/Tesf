#include "telemetry.h"

#include "memory_safe.h"

#include <dlfcn.h>
#include <algorithm>
#include <cmath>
#include <cstring>
#include <mutex>
#include <vector>

static const char* kGameLib = "libgame-SSM-GooglePlay-Gold-Release-Module-1013.so";

struct SymbolCache {
    bool resolved = false;
    float* internal_velocity = nullptr;
    uint8_t* physics_debug = nullptr;
    uint8_t* physics_diag = nullptr;
};

struct RepeatedPtrFieldView {
    void* arena = nullptr;
    int32_t current_size = 0;
    int32_t total_size = 0;
    void* rep = nullptr;
};

static SymbolCache g_syms;
static MatchSnapshot g_hook_cache;
static std::mutex g_hook_mutex;
static int g_hook_events = 0;

static void* gameHandle() {
    return dlopen(kGameLib, RTLD_NOLOAD);
}

bool isGameLibLoaded() {
    return gameHandle() != nullptr;
}

void resetLiveScanState() {
    g_syms.resolved = false;
    std::lock_guard<std::mutex> lock(g_hook_mutex);
    g_hook_cache = MatchSnapshot{};
    g_hook_events = 0;
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

static bool isValidPtr(const void* p) {
    uintptr_t v = (uintptr_t)p;
    return v > 0x10000 && v < 0x7fffffffffffULL;
}

static bool looksLikeRepeatedPtrField(const RepeatedPtrFieldView& r) {
    if (!isValidPtr(r.arena) || !isValidPtr(r.rep)) return false;
    if (!isReadable(r.arena, sizeof(void*)) || !isReadable(r.rep, 16)) return false;
    if (r.current_size < 1 || r.current_size > 25) return false;
    if (r.total_size < r.current_size || r.total_size > 40) return false;
    return true;
}

static bool readRepeatedPtrField(const uint8_t* base, RepeatedPtrFieldView& out) {
    if (!safeRead(base, &out, sizeof(RepeatedPtrFieldView))) return false;
    return looksLikeRepeatedPtrField(out);
}

static bool parsePuckPosition(void* puck_obj, float& x, float& y, int32_t& puck_id) {
    if (!isValidPtr(puck_obj) || !isReadable(puck_obj, 96)) return false;
    auto* p = reinterpret_cast<uint8_t*>(puck_obj);

    for (size_t off = 16; off < 96; off += 8) {
        void* pos_ptr = nullptr;
        if (!safeRead(p + off, &pos_ptr, sizeof(void*))) continue;
        if (!isValidPtr(pos_ptr) || !isReadable(pos_ptr, 16)) continue;

        double dx = 0, dy = 0;
        if (!safeRead(pos_ptr, &dx, sizeof(double))) continue;
        if (!safeRead(reinterpret_cast<uint8_t*>(pos_ptr) + 8, &dy, sizeof(double))) continue;
        if (dx < 0.02 || dx > 0.98 || dy < 0.05 || dy > 0.95) continue;

        x = (float)dx;
        y = (float)dy;
        if (off + 12 <= 96) {
            safeRead(p + off + 8, &puck_id, sizeof(int32_t));
        }
        return true;
    }
    return false;
}

static void ingestFieldState(const RepeatedPtrFieldView& field, MatchSnapshot& snap) {
    if (!looksLikeRepeatedPtrField(field)) return;

    int32_t allocated = 0;
    void** elements = nullptr;
    if (!safeRead(field.rep, &allocated, sizeof(int32_t))) return;
    if (!safeRead(reinterpret_cast<uint8_t*>(field.rep) + 8, &elements, sizeof(void**))) return;
    if (!isValidPtr(elements) || !isReadable(elements, field.current_size * sizeof(void*))) return;

    snap.body_count = field.current_size;
    snap.puck_estimate = 0;
    snap.ball_valid = false;

    float best_ball_score = -1.f;
    int puck_count = 0;

    for (int i = 0; i < field.current_size; i++) {
        void* puck_obj = nullptr;
        if (!safeRead(elements + i, &puck_obj, sizeof(void*))) continue;
        if (!puck_obj) continue;

        float x = 0, y = 0;
        int32_t puck_id = -1;
        if (!parsePuckPosition(puck_obj, x, y, puck_id)) continue;

        puck_count++;
        float dist = std::hypot(x - 0.5f, y - 0.5f);
        float score = (puck_id == 0 ? 2.f : 0.f) + (1.f - dist);
        if (score > best_ball_score) {
            best_ball_score = score;
            snap.ball_x = x;
            snap.ball_y = y;
            snap.ball_valid = true;
        }
    }

    snap.puck_estimate = puck_count - (snap.ball_valid ? 1 : 0);
}

static bool tryParseShotOutcomeAt(const uint8_t* obj, size_t max_len, MatchSnapshot& snap) {
    if (!obj || max_len < 160 || !isReadable(obj, 160)) return false;

    for (size_t off = 0; off + 160 <= max_len; off += 8) {
        RepeatedPtrFieldView field_state{};
        RepeatedPtrFieldView goal_bonus{};
        if (!readRepeatedPtrField(obj + off, field_state)) continue;
        if (!readRepeatedPtrField(obj + off + 16, goal_bonus)) continue;

        for (size_t score_off = off + 32; score_off + 8 < off + 160; score_off += 4) {
            int32_t home = 0, away = 0;
            if (!safeRead(obj + score_off, &home, sizeof(int32_t))) continue;
            if (!safeRead(obj + score_off + 4, &away, sizeof(int32_t))) continue;
            if (home < 0 || home > 15 || away < 0 || away > 15) continue;
            if (home + away > 25) continue;

            snap.score_home = (float)home;
            snap.score_away = (float)away;
            ingestFieldState(field_state, snap);
            return true;
        }
    }
    return false;
}

bool parseShotOutcomeObject(const void* obj, MatchSnapshot& out) {
    if (!obj || !isReadable(obj, 256)) return false;
    MatchSnapshot snap;
    if (!tryParseShotOutcomeAt(reinterpret_cast<const uint8_t*>(obj), 256, snap)) return false;
    out.score_home = snap.score_home;
    out.score_away = snap.score_away;
    out.ball_x = snap.ball_x;
    out.ball_y = snap.ball_y;
    out.ball_valid = snap.ball_valid;
    out.body_count = snap.body_count;
    out.puck_estimate = snap.puck_estimate;
    out.data_source = DataSource::HookShotOutcome;
    return true;
}

bool parseGameStartedObject(const void* obj, MatchSnapshot& out) {
    if (!obj || !isReadable(obj, 128)) return false;
    const auto* p = reinterpret_cast<const uint8_t*>(obj);

    for (size_t off = 0; off + 32 < 128; off += 8) {
        RepeatedPtrFieldView field{};
        if (!readRepeatedPtrField(p + off, field)) continue;
        MatchSnapshot snap;
        ingestFieldState(field, snap);
        if (snap.body_count > 0) {
            out.ball_x = snap.ball_x;
            out.ball_y = snap.ball_y;
            out.ball_valid = snap.ball_valid;
            out.body_count = snap.body_count;
            out.puck_estimate = snap.puck_estimate;
            out.data_source = DataSource::HookGameStarted;
            return true;
        }
    }
    return false;
}

void commitHookSnapshot(const MatchSnapshot& snap) {
    std::lock_guard<std::mutex> lock(g_hook_mutex);
    g_hook_cache = snap;
    g_hook_events++;
}

void mergeHookSnapshot(MatchSnapshot& dst) {
    std::lock_guard<std::mutex> lock(g_hook_mutex);
    dst.hook_events = g_hook_events;
    if (g_hook_cache.data_source == DataSource::None) return;

    dst.data_source = g_hook_cache.data_source;
    if (g_hook_cache.score_home >= 0.f) dst.score_home = g_hook_cache.score_home;
    if (g_hook_cache.score_away >= 0.f) dst.score_away = g_hook_cache.score_away;
    if (g_hook_cache.ball_valid) {
        dst.ball_valid = true;
        dst.ball_x = g_hook_cache.ball_x;
        dst.ball_y = g_hook_cache.ball_y;
    }
    dst.body_count = g_hook_cache.body_count;
    dst.puck_estimate = g_hook_cache.puck_estimate;
}
