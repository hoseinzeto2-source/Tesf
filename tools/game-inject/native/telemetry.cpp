#include "telemetry.h"

#include <dlfcn.h>
#include <cstdio>
#include <cmath>
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
            if (speed > 6.0f) continue;

            bool dup = false;
            for (const auto& b : out) {
                if (std::fabs(b.x - x) < 0.025f && std::fabs(b.y - y) < 0.025f) {
                    dup = true;
                    break;
                }
            }
            if (!dup) {
                out.push_back({x, y, vx, vy, speed});
                if ((int)out.size() >= maxBodies) break;
            }
        }
        if ((int)out.size() >= maxBodies) break;
    }
    fclose(f);
    return out;
}

float readInternalVelocity() {
    void* handle = dlopen(kGameLib, RTLD_NOLOAD);
    if (!handle) handle = dlopen(kGameLib, RTLD_NOW);
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
