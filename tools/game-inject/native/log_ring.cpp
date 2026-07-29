#include "log_ring.h"

#include <cstring>
#include <mutex>

static char g_ring[8192];
static std::mutex g_ring_mutex;

void logRingInit() {
    std::lock_guard<std::mutex> lock(g_ring_mutex);
    g_ring[0] = '\0';
}

void logRingAppend(const char* msg) {
    if (!msg) return;
    std::lock_guard<std::mutex> lock(g_ring_mutex);
    size_t len = strlen(g_ring);
    if (len > 7000) {
        memcpy(g_ring, g_ring + 3500, len - 3500 + 1);
        len = strlen(g_ring);
    }
    if (len > 0 && g_ring[len - 1] != '\n') {
        strncat(g_ring, "\n", sizeof(g_ring) - len - 1);
        len = strlen(g_ring);
    }
    strncat(g_ring, msg, sizeof(g_ring) - len - 1);
}

void logRingGet(char* out, size_t cap) {
    if (!out || cap == 0) return;
    std::lock_guard<std::mutex> lock(g_ring_mutex);
    strncpy(out, g_ring, cap - 1);
    out[cap - 1] = '\0';
}
