/*
 * ssm_memscan — GameGuardian-style read-only heap scan for Soccer Stars.
 * Run as root: ssm_memscan <pid> [expect_home expect_away]
 * Writes JSON to stdout and /sdcard/Download/ssm_memscan.json
 */
#define _GNU_SOURCE
#include <errno.h>
#include <fcntl.h>
#include <math.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/uio.h>
#include <unistd.h>

typedef struct {
    uintptr_t start, end;
} Range;

static int on_pitch(float x, float y) {
    return x > 0.03f && x < 0.97f && y > 0.06f && y < 0.94f;
}

static int finite4(float a, float b, float c, float d) {
    return isfinite(a) && isfinite(b) && isfinite(c) && isfinite(d);
}

static ssize_t pread_mem(pid_t pid, void *buf, size_t len, uintptr_t addr) {
    struct iovec local = {buf, len};
    struct iovec remote = {(void *)addr, len};
    return process_vm_readv(pid, &local, 1, &remote, 1, 0);
}

static int load_maps(pid_t pid, Range *out, int maxn) {
    char path[64];
    snprintf(path, sizeof(path), "/proc/%d/maps", pid);
    FILE *f = fopen(path, "r");
    if (!f) return -1;
    char line[512];
    int n = 0;
    while (fgets(line, sizeof(line), f) && n < maxn) {
        unsigned long s = 0, e = 0;
        char perms[8] = {0};
        if (sscanf(line, "%lx-%lx %4s", &s, &e, perms) < 3) continue;
        if (perms[0] != 'r' || perms[1] != 'w') continue;
        if (e <= s) continue;
        size_t sz = (size_t)(e - s);
        if (sz < 64 || sz > 16 * 1024 * 1024) continue;
        if (strstr(line, "libEGL") || strstr(line, "libc.so") || strstr(line, "libssm_research"))
            continue;
        out[n].start = (uintptr_t)s;
        out[n].end = (uintptr_t)e;
        n++;
    }
    fclose(f);
    return n;
}

int main(int argc, char **argv) {
    if (argc < 2) {
        fprintf(stderr, "usage: %s <pid> [home away]\n", argv[0]);
        return 2;
    }
    pid_t pid = (pid_t)atoi(argv[1]);
    int want_home = -1, want_away = -1;
    if (argc >= 4) {
        want_home = atoi(argv[2]);
        want_away = atoi(argv[3]);
    }

    Range maps[128];
    int nm = load_maps(pid, maps, 128);
    if (nm < 0) {
        fprintf(stderr, "maps fail: %s\n", strerror(errno));
        return 1;
    }

    int best_home = -1, best_away = -1, best_rank = -1;
    uintptr_t best_addr = 0;
    int bodies = 0;
    float bx = 0, by = 0;
    int ball_ok = 0;
    float best_ball_dist = 1e9f;

    uint8_t *chunk = (uint8_t *)malloc(2 * 1024 * 1024);
    if (!chunk) return 1;

    for (int i = 0; i < nm; i++) {
        size_t len = maps[i].end - maps[i].start;
        if (len > 2 * 1024 * 1024) len = 2 * 1024 * 1024;
        if (pread_mem(pid, chunk, len, maps[i].start) != (ssize_t)len) continue;

        for (size_t off = 0; off + 16 <= len; off += 4) {
            float x, y, vx, vy;
            memcpy(&x, chunk + off, 4);
            memcpy(&y, chunk + off + 4, 4);
            memcpy(&vx, chunk + off + 8, 4);
            memcpy(&vy, chunk + off + 12, 4);
            if (!finite4(x, y, vx, vy)) continue;
            if (!on_pitch(x, y)) continue;
            float sp = hypotf(vx, vy);
            if (sp > 8.f) continue;
            bodies++;
            float dist = hypotf(x - 0.5f, y - 0.5f);
            if (sp < 2.5f && dist < best_ball_dist) {
                best_ball_dist = dist;
                bx = x;
                by = y;
                ball_ok = 1;
            }
        }

        for (size_t off = 0; off + 8 <= len; off += 4) {
            int32_t a, b;
            memcpy(&a, chunk + off, 4);
            memcpy(&b, chunk + off + 4, 4);
            if (a < 0 || b < 0 || a > 12 || b > 12 || a + b > 20) continue;
            if (want_home >= 0 && (a != want_home || b != want_away)) continue;

            int nearby = 0;
            size_t lo = off > 256 ? off - 256 : 0;
            size_t hi = off + 256 < len ? off + 256 : len;
            for (size_t q = lo; q + 8 <= hi; q += 4) {
                float fx, fy;
                memcpy(&fx, chunk + q, 4);
                memcpy(&fy, chunk + q + 4, 4);
                if (isfinite(fx) && isfinite(fy) && on_pitch(fx, fy)) nearby++;
            }
            int rank = nearby * 10 + (a + b);
            if (want_home >= 0) rank += 1000; /* preferred exact match */
            if (rank > best_rank && nearby >= 2) {
                best_rank = rank;
                best_home = a;
                best_away = b;
                best_addr = maps[i].start + off;
            }
        }
    }
    free(chunk);

    char json[512];
    snprintf(json, sizeof(json),
             "{\"pid\":%d,\"maps\":%d,\"score_home\":%d,\"score_away\":%d,"
             "\"rank\":%d,\"addr\":\"0x%lx\",\"bodies\":%d,"
             "\"ball_valid\":%s,\"ball_x\":%.5f,\"ball_y\":%.5f,"
             "\"expect\":\"%d:%d\"}\n",
             (int)pid, nm, best_home, best_away, best_rank,
             (unsigned long)best_addr, bodies,
             ball_ok ? "true" : "false", bx, by,
             want_home, want_away);
    fputs(json, stdout);

    FILE *out = fopen("/sdcard/Download/ssm_memscan.json", "w");
    if (out) {
        fputs(json, out);
        fclose(out);
    }
    return (best_home >= 0) ? 0 : 3;
}
