#include "memory_safe.h"

#include <cstdio>
#include <cstring>
#include <vector>

struct MapRange {
    uintptr_t start;
    uintptr_t end;
};

static std::vector<MapRange> g_readable;
static std::vector<MapRange> g_writable;

static void parseMaps(bool want_write) {
    auto& out = want_write ? g_writable : g_readable;
    out.clear();
    FILE* f = fopen("/proc/self/maps", "r");
    if (!f) return;

    char line[512];
    while (fgets(line, sizeof(line), f)) {
        unsigned long start = 0, end = 0;
        char perms[8] = {};
        if (sscanf(line, "%lx-%lx %4s", &start, &end, perms) < 3) continue;
        if (perms[0] != 'r') continue;
        if (want_write && perms[1] != 'w') continue;
        if (end <= start) continue;
        out.push_back({start, end});
    }
    fclose(f);
}

void refreshWritableMaps() {
    parseMaps(true);
}

void refreshReadableMaps() {
    parseMaps(false);
    parseMaps(true);
}

bool isReadable(const void* ptr, size_t len) {
    if (!ptr || len == 0) return false;
    uintptr_t start = (uintptr_t)ptr;
    uintptr_t finish = start + len;
    if (finish < start) return false;

    if (g_readable.empty()) refreshReadableMaps();

    for (const auto& r : g_readable) {
        if (start >= r.start && finish <= r.end) return true;
    }
    return false;
}

bool isWritable(const void* ptr, size_t len) {
    if (!ptr || len == 0) return false;
    uintptr_t start = (uintptr_t)ptr;
    uintptr_t finish = start + len;
    if (finish < start) return false;

    if (g_writable.empty()) refreshWritableMaps();

    for (const auto& r : g_writable) {
        if (start >= r.start && finish <= r.end) return true;
    }
    return false;
}

bool safeRead(const void* src, void* dst, size_t len) {
    if (!isReadable(src, len)) return false;
    memcpy(dst, src, len);
    return true;
}

bool safeWrite(void* dst, const void* src, size_t len) {
    if (!isWritable(dst, len)) return false;
    memcpy(dst, src, len);
    return true;
}
