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
static int g_hooks_patched = 0;

static void* gameHandle() {
    return dlopen(kGameLib, RTLD_NOLOAD);
}

bool isGameLibLoaded() {
    return gameHandle() != nullptr;
}

int getHookPatchedCount() {
    std::lock_guard<std::mutex> lock(g_hook_mutex);
    return g_hooks_patched;
}

int getHookEventCount() {
    std::lock_guard<std::mutex> lock(g_hook_mutex);
    return g_hook_events;
}

void resetLiveScanState() {
    g_syms.resolved = false;
    std::lock_guard<std::mutex> lock(g_hook_mutex);
    g_hook_cache = MatchSnapshot{};
    g_hook_events = 0;
    g_hooks_patched = 0;
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
        if (off + 12 <= 96) safeRead(p + off + 8, &puck_id, sizeof(int32_t));
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

static bool tryParseShotOutcomeFast(const uint8_t* obj, MatchSnapshot& snap) {
  // protobuf shot_outcome layout (build 1013): field_state @ +24, scores @ +116
    constexpr size_t kFieldOff = 24;
    constexpr size_t kScoreOff = 116;

    if (!isReadable(obj, kScoreOff + 8)) return false;

    RepeatedPtrFieldView field_state{};
    if (!readRepeatedPtrField(obj + kFieldOff, field_state)) return false;

    RepeatedPtrFieldView goal_bonus{};
    if (!readRepeatedPtrField(obj + kFieldOff + 16, goal_bonus)) return false;

    int32_t home = 0, away = 0;
    if (!safeRead(obj + kScoreOff, &home, sizeof(int32_t))) return false;
    if (!safeRead(obj + kScoreOff + 4, &away, sizeof(int32_t))) return false;
    if (home < 0 || home > 15 || away < 0 || away > 15 || home + away > 25) return false;

    snap.score_home = (float)home;
    snap.score_away = (float)away;
    ingestFieldState(field_state, snap);
    return true;
}

static bool tryParseShotOutcomeScan(const uint8_t* obj, size_t max_len, MatchSnapshot& snap) {
    if (!obj || max_len < 160 || !isReadable(obj, 160)) return false;

    for (size_t off = 0; off + 160 <= max_len; off += 8) {
        RepeatedPtrFieldView field_state{};
        RepeatedPtrFieldView goal_bonus{};
        if (!readRepeatedPtrField(obj + off, field_state)) continue;
        if (!readRepeatedPtrField(obj + off + 16, goal_bonus)) continue;

        for (size_t score_off = off + 32; score_off + 8 < off + 180 && score_off + 8 <= max_len; score_off += 4) {
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

static void copySnapshotFields(const MatchSnapshot& src, MatchSnapshot& dst) {
    if (src.score_home >= 0.f) dst.score_home = src.score_home;
    if (src.score_away >= 0.f) dst.score_away = src.score_away;
    dst.ball_x = src.ball_x;
    dst.ball_y = src.ball_y;
    dst.ball_valid = src.ball_valid;
    dst.body_count = src.body_count;
    dst.puck_estimate = src.puck_estimate;
    dst.data_source = src.data_source;
    if (src.shot_angle >= 0.f) dst.shot_angle = src.shot_angle;
    if (src.shot_power >= 0.f) dst.shot_power = src.shot_power;
}

bool parseShotOutcomeObject(const void* obj, MatchSnapshot& out) {
    if (!obj || !isReadable(obj, 128)) return false;
    const auto* p = reinterpret_cast<const uint8_t*>(obj);

    MatchSnapshot snap;
    if (tryParseShotOutcomeFast(p, snap) || tryParseShotOutcomeScan(p, 512, snap)) {
        copySnapshotFields(snap, out);
        out.data_source = DataSource::HookShotOutcome;
        return true;
    }
    return false;
}

bool parseGameStartedObject(const void* obj, MatchSnapshot& out) {
    if (!obj || !isReadable(obj, 128)) return false;
    const auto* p = reinterpret_cast<const uint8_t*>(obj);

    constexpr size_t kFieldOff = 24;
    RepeatedPtrFieldView field{};
    if (readRepeatedPtrField(p + kFieldOff, field)) {
        MatchSnapshot snap;
        ingestFieldState(field, snap);
        if (snap.body_count > 0) {
            copySnapshotFields(snap, out);
            out.data_source = DataSource::HookGameStarted;
            return true;
        }
    }

    for (size_t off = 0; off + 32 < 128; off += 8) {
        RepeatedPtrFieldView field_scan{};
        if (!readRepeatedPtrField(p + off, field_scan)) continue;
        MatchSnapshot snap;
        ingestFieldState(field_scan, snap);
        if (snap.body_count > 0) {
            copySnapshotFields(snap, out);
            out.data_source = DataSource::HookGameStarted;
            return true;
        }
    }
    return false;
}

bool parseShotTakenObject(const void* obj, MatchSnapshot& out) {
    if (!obj || !isReadable(obj, 80)) return false;
    const auto* p = reinterpret_cast<const uint8_t*>(obj);

    constexpr size_t kFieldOff = 24;
    constexpr size_t kAngleOff = 56;
    constexpr size_t kPowerOff = 64;

    RepeatedPtrFieldView field{};
    if (!readRepeatedPtrField(p + kFieldOff, field)) return false;

    MatchSnapshot snap;
    ingestFieldState(field, snap);
    if (snap.body_count <= 0) return false;

    double angle = 0, power = 0;
    if (safeRead(p + kAngleOff, &angle, sizeof(double))) {
        if (angle >= -6.5 && angle <= 6.5) snap.shot_angle = (float)angle;
    }
    if (safeRead(p + kPowerOff, &power, sizeof(double))) {
        if (power >= 0.f && power <= 2.f) snap.shot_power = (float)power;
    }

    copySnapshotFields(snap, out);
    out.data_source = DataSource::HookShotTaken;
    return true;
}

static bool parseNetworkRequestFast(const uint8_t* req, MatchSnapshot& out) {
    // req protobuf pointer fields (build 1013 layout from type encoding)
    constexpr size_t kOffShotTaken = 392;
    constexpr size_t kOffShotOutcome = 416;
    constexpr size_t kOffGameStarted = 608;

    if (!isReadable(req, kOffGameStarted + 8)) return false;

    void* shot_taken = nullptr;
    void* shot_outcome = nullptr;
    void* game_started = nullptr;
    if (!safeRead(req + kOffShotOutcome, &shot_outcome, sizeof(void*))) return false;
    if (!safeRead(req + kOffShotTaken, &shot_taken, sizeof(void*))) return false;
    if (!safeRead(req + kOffGameStarted, &game_started, sizeof(void*))) return false;

    MatchSnapshot snap;
    if (isValidPtr(shot_outcome) && parseShotOutcomeObject(shot_outcome, snap)) {
        copySnapshotFields(snap, out);
        out.data_source = DataSource::HookNetworkReq;
        return true;
    }
    if (isValidPtr(game_started) && parseGameStartedObject(game_started, snap)) {
        copySnapshotFields(snap, out);
        out.data_source = DataSource::HookNetworkReq;
        return true;
    }
    if (isValidPtr(shot_taken) && parseShotTakenObject(shot_taken, snap)) {
        copySnapshotFields(snap, out);
        out.data_source = DataSource::HookNetworkReq;
        return true;
    }
    return false;
}

static bool parseNetworkRequestScan(const uint8_t* p, MatchSnapshot& out) {
    for (size_t off = 0; off + 8 < 1024; off += 8) {
        void* ptr = nullptr;
        if (!safeRead(p + off, &ptr, sizeof(void*))) continue;
        if (!isValidPtr(ptr)) continue;

        MatchSnapshot snap;
        if (parseShotOutcomeObject(ptr, snap)) {
            copySnapshotFields(snap, out);
            out.data_source = DataSource::HookNetworkReq;
            return true;
        }
        if (parseGameStartedObject(ptr, snap)) {
            copySnapshotFields(snap, out);
            out.data_source = DataSource::HookNetworkReq;
            return true;
        }
        if (parseShotTakenObject(ptr, snap)) {
            copySnapshotFields(snap, out);
            out.data_source = DataSource::HookNetworkReq;
            return true;
        }
    }
    return false;
}

bool parseNetworkRequest(const void* req, MatchSnapshot& out) {
    if (!req || !isReadable(req, 128)) return false;
    const auto* p = reinterpret_cast<const uint8_t*>(req);

    if (parseNetworkRequestFast(p, out)) return true;
    return parseNetworkRequestScan(p, out);
}

void commitHookSnapshot(const MatchSnapshot& snap, const char* sel_name) {
    std::lock_guard<std::mutex> lock(g_hook_mutex);

    if (snap.score_home >= 0.f) g_hook_cache.score_home = snap.score_home;
    if (snap.score_away >= 0.f) g_hook_cache.score_away = snap.score_away;
    if (snap.ball_valid) {
        g_hook_cache.ball_valid = true;
        g_hook_cache.ball_x = snap.ball_x;
        g_hook_cache.ball_y = snap.ball_y;
    }
    if (snap.body_count > 0) {
        g_hook_cache.body_count = snap.body_count;
        g_hook_cache.puck_estimate = snap.puck_estimate;
    }
    if (snap.shot_angle >= 0.f) g_hook_cache.shot_angle = snap.shot_angle;
    if (snap.shot_power >= 0.f) g_hook_cache.shot_power = snap.shot_power;
    if (snap.data_source != DataSource::None) g_hook_cache.data_source = snap.data_source;

    g_hook_events++;
    if (sel_name) {
        strncpy(g_hook_cache.last_hook_sel, sel_name, sizeof(g_hook_cache.last_hook_sel) - 1);
        g_hook_cache.last_hook_sel[sizeof(g_hook_cache.last_hook_sel) - 1] = '\0';
    }
}

void mergeHookSnapshot(MatchSnapshot& dst) {
    std::lock_guard<std::mutex> lock(g_hook_mutex);
    dst.hook_events = g_hook_events;
    dst.hooks_patched = g_hooks_patched;
    if (g_hook_cache.data_source == DataSource::None) return;

    // Prefer hook data when present; heap scan fills gaps below via commitHeapSnapshot
    dst.data_source = g_hook_cache.data_source;
    if (g_hook_cache.score_home >= 0.f) dst.score_home = g_hook_cache.score_home;
    if (g_hook_cache.score_away >= 0.f) dst.score_away = g_hook_cache.score_away;
    if (g_hook_cache.ball_valid) {
        dst.ball_valid = true;
        dst.ball_x = g_hook_cache.ball_x;
        dst.ball_y = g_hook_cache.ball_y;
    }
    if (g_hook_cache.body_count > 0) {
        dst.body_count = g_hook_cache.body_count;
        dst.puck_estimate = g_hook_cache.puck_estimate;
    }
    if (g_hook_cache.shot_angle >= 0.f) dst.shot_angle = g_hook_cache.shot_angle;
    if (g_hook_cache.shot_power >= 0.f) dst.shot_power = g_hook_cache.shot_power;
    memcpy(dst.last_hook_sel, g_hook_cache.last_hook_sel, sizeof(dst.last_hook_sel));
}

void commitHeapSnapshot(const MatchSnapshot& snap) {
    std::lock_guard<std::mutex> lock(g_hook_mutex);
    // Only fill fields hooks have not provided yet
    if (g_hook_cache.score_home < 0.f && snap.score_home >= 0.f) {
        g_hook_cache.score_home = snap.score_home;
        g_hook_cache.score_away = snap.score_away;
    }
    if (!g_hook_cache.ball_valid && snap.ball_valid) {
        g_hook_cache.ball_valid = true;
        g_hook_cache.ball_x = snap.ball_x;
        g_hook_cache.ball_y = snap.ball_y;
    }
    if (g_hook_cache.body_count <= 0 && snap.body_count > 0) {
        g_hook_cache.body_count = snap.body_count;
        g_hook_cache.puck_estimate = snap.puck_estimate;
    }
    if (g_hook_cache.data_source == DataSource::None && snap.data_source != DataSource::None) {
        g_hook_cache.data_source = snap.data_source;
        strncpy(g_hook_cache.last_hook_sel, "heap_scan", sizeof(g_hook_cache.last_hook_sel) - 1);
    }
}

void telemetrySetHookPatchedCount(int n) {
    std::lock_guard<std::mutex> lock(g_hook_mutex);
    g_hooks_patched = n;
}
