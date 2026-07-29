#include "heap_scan.h"

#include "memory_safe.h"

#include <android/log.h>
#include <cmath>
#include <cstdint>
#include <cstring>
#include <vector>

#define LOG_TAG "SSMResearchHUD"
#define LOGI(...) __android_log_print(ANDROID_LOG_INFO, LOG_TAG, __VA_ARGS__)

struct MapRange {
    uintptr_t start = 0;
    uintptr_t end = 0;
};

struct BodyHit {
    float x = 0;
    float y = 0;
    float vx = 0;
    float vy = 0;
};

static bool onPitch(float x, float y) {
    return x > 0.03f && x < 0.97f && y > 0.06f && y < 0.94f;
}

static bool finite4(float a, float b, float c, float d) {
    return std::isfinite(a) && std::isfinite(b) && std::isfinite(c) && std::isfinite(d);
}

static std::vector<MapRange> loadRwMaps() {
    std::vector<MapRange> out;
    FILE* f = fopen("/proc/self/maps", "r");
    if (!f) return out;
    char line[512];
    while (fgets(line, sizeof(line), f)) {
        unsigned long start = 0, end = 0;
        char perms[8] = {};
        if (sscanf(line, "%lx-%lx %4s", &start, &end, perms) < 3) continue;
        if (perms[0] != 'r' || perms[1] != 'w') continue;
        // Skip huge mappings
        if (end <= start) continue;
        size_t sz = (size_t)(end - start);
        if (sz < 64 || sz > 12 * 1024 * 1024) continue;
        // Prefer anonymous / ashmem / allocator heaps
        if (strstr(line, "libssm_research") || strstr(line, "libEGL") || strstr(line, "libc.so"))
            continue;
        out.push_back({start, end});
        if (out.size() > 80) break;
    }
    fclose(f);
    return out;
}

static void dedupeBodies(std::vector<BodyHit>& bodies) {
    std::vector<BodyHit> unique;
    for (const auto& b : bodies) {
        bool dup = false;
        for (const auto& u : unique) {
            if (std::fabs(u.x - b.x) < 0.02f && std::fabs(u.y - b.y) < 0.02f) {
                dup = true;
                break;
            }
        }
        if (!dup) unique.push_back(b);
    }
    bodies.swap(unique);
}

static bool pickBall(const std::vector<BodyHit>& bodies, float& bx, float& by) {
    if (bodies.empty()) return false;
    float best = -1.f;
    for (const auto& b : bodies) {
        float dist = std::hypot(b.x - 0.5f, b.y - 0.5f);
        float score = (1.f - dist) + (b.vx * b.vx + b.vy * b.vy) * 0.05f;
        // Prefer near-center-ish or unique; ball often more central early
        if (score > best) {
            best = score;
            bx = b.x;
            by = b.y;
        }
    }
    // Prefer body closest to geometric median of all? For now: closest to 0.5,0.5 among low-speed
    best = 1e9f;
    bool found = false;
    for (const auto& b : bodies) {
        float sp = std::hypot(b.vx, b.vy);
        float dist = std::hypot(b.x - 0.5f, b.y - 0.5f);
        if (sp < 2.5f && dist < best) {
            best = dist;
            bx = b.x;
            by = b.y;
            found = true;
        }
    }
    return found || !bodies.empty();
}

static bool scanScoreNear(const uint8_t* base, size_t len, int& home, int& away) {
    // Look for int32 score pairs 0..15 with sum <= 20
    for (size_t off = 0; off + 8 <= len; off += 4) {
        int32_t a = 0, b = 0;
        memcpy(&a, base + off, 4);
        memcpy(&b, base + off + 4, 4);
        if (a < 0 || b < 0 || a > 12 || b > 12) continue;
        if (a + b > 20) continue;
        // Prefer non-trivial scores when possible, but allow 0-0 during match
        home = a;
        away = b;
        return true;
    }
    return false;
}

bool heapScanMatch(MatchSnapshot& out) {
    refreshReadableMaps();
    auto maps = loadRwMaps();
    if (maps.empty()) return false;

    std::vector<BodyHit> bodies;
    bodies.reserve(64);

    int best_home = -1, best_away = -1;
    int best_score_rank = -1;

    for (const auto& m : maps) {
        size_t len = (size_t)(m.end - m.start);
        if (!isReadable(reinterpret_cast<const void*>(m.start), len > 4096 ? 4096 : len)) {
            // try page by page
        }

        // Cap per-region scan for frame budget
        size_t scan_len = len;
        if (scan_len > 2 * 1024 * 1024) scan_len = 2 * 1024 * 1024;

        const uint8_t* p = reinterpret_cast<const uint8_t*>(m.start);
        for (size_t off = 0; off + 16 <= scan_len; off += 4) {
            if (!isReadable(p + off, 16)) {
                off = (off + 4096) & ~size_t(4095);
                if (off > 0) off -= 4;
                continue;
            }

            float x = 0, y = 0, vx = 0, vy = 0;
            memcpy(&x, p + off, 4);
            memcpy(&y, p + off + 4, 4);
            memcpy(&vx, p + off + 8, 4);
            memcpy(&vy, p + off + 12, 4);
            if (!finite4(x, y, vx, vy)) continue;
            if (!onPitch(x, y)) continue;
            float sp = std::hypot(vx, vy);
            if (sp > 8.f) continue;

            BodyHit hit{x, y, vx, vy};
            bodies.push_back(hit);
            if (bodies.size() > 120) break;
        }

        // Score hunt: adjacent int32s; rank higher if near many pitch floats
        for (size_t off = 0; off + 8 <= scan_len; off += 4) {
            if (!isReadable(p + off, 8)) continue;
            int32_t a = 0, b = 0;
            memcpy(&a, p + off, 4);
            memcpy(&b, p + off + 4, 4);
            if (a < 0 || b < 0 || a > 12 || b > 12 || a + b > 20) continue;

            int nearby = 0;
            size_t lo = off > 256 ? off - 256 : 0;
            size_t hi = off + 256 < scan_len ? off + 256 : scan_len;
            for (size_t q = lo; q + 8 <= hi; q += 4) {
                float fx = 0, fy = 0;
                memcpy(&fx, p + q, 4);
                memcpy(&fy, p + q + 4, 4);
                if (std::isfinite(fx) && std::isfinite(fy) && onPitch(fx, fy)) nearby++;
            }
            int rank = nearby * 10 + (a + b);
            if (rank > best_score_rank && nearby >= 3) {
                best_score_rank = rank;
                best_home = a;
                best_away = b;
            }
        }

        if (bodies.size() > 80 && best_score_rank > 30) break;
    }

    dedupeBodies(bodies);
    if (bodies.size() > 16) bodies.resize(16);

    bool ok = false;
    if (bodies.size() >= 3) {
        float bx = 0, by = 0;
        if (pickBall(bodies, bx, by)) {
            out.ball_x = bx;
            out.ball_y = by;
            out.ball_valid = true;
        }
        out.body_count = (int)bodies.size();
        out.puck_estimate = (int)bodies.size() - (out.ball_valid ? 1 : 0);
        ok = true;
    }

    if (best_home >= 0 && best_away >= 0 && best_score_rank >= 20) {
        out.score_home = (float)best_home;
        out.score_away = (float)best_away;
        ok = true;
    }

    if (ok) {
        out.data_source = DataSource::HeapScan;
        LOGI("heap: score=%d:%d bodies=%d ball=%d (%.3f,%.3f) rank=%d",
             best_home, best_away, out.body_count, out.ball_valid ? 1 : 0,
             out.ball_x, out.ball_y, best_score_rank);
    }
    return ok;
}
