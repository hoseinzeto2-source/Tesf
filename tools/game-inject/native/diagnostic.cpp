#include "diagnostic.h"

#include "log_ring.h"
#include "memory_safe.h"

#include <android/log.h>
#include <dlfcn.h>
#include <jni.h>
#include <cstdarg>
#include <cstdio>
#include <cstring>

#define LOG_TAG "SSMResearchHUD"

static JavaVM* g_jvm = nullptr;

void diagnosticSetJavaVm(void* vm) {
    g_jvm = static_cast<JavaVM*>(vm);
}

static void jsonEsc(const char* s, char* out, size_t cap) {
    if (!out || cap == 0) return;
    out[0] = '\0';
    if (!s) return;
    size_t n = 0;
    for (const char* p = s; *p && n + 2 < cap; p++) {
        char c = *p;
        if (c == '"' || c == '\\') {
            if (n + 2 >= cap) break;
            out[n++] = '\\';
            out[n++] = c;
        } else if (c == '\n') {
            if (n + 2 >= cap) break;
            out[n++] = '\\';
            out[n++] = 'n';
        } else if (c >= 32 && c < 127) {
            out[n++] = c;
        }
    }
    out[n] = '\0';
}

static void appendf(char* out, size_t cap, size_t* pos, const char* fmt, ...) {
    if (!out || !pos || *pos >= cap) return;
    va_list ap;
    va_start(ap, fmt);
    int n = vsnprintf(out + *pos, cap - *pos, fmt, ap);
    va_end(ap);
    if (n > 0) *pos += (size_t)n;
    if (*pos >= cap) *pos = cap - 1;
}

static const char* dataSourceStr(DataSource ds) {
    switch (ds) {
        case DataSource::HookShotOutcome: return "shot_outcome";
        case DataSource::HookGameStarted: return "game_started";
        case DataSource::HookNetworkReq: return "network_req";
        case DataSource::HookShotTaken: return "shot_taken";
        case DataSource::PhysicsExports: return "physics_exports";
        default: return "none";
    }
}

static bool writeBytesJni(const char* path, const char* data, size_t len, char* err, size_t err_cap) {
    if (!g_jvm || !path || !data) {
        snprintf(err, err_cap, "jni not ready");
        return false;
    }
    JNIEnv* env = nullptr;
    if (g_jvm->AttachCurrentThread(&env, nullptr) != JNI_OK || !env) {
        snprintf(err, err_cap, "jni attach failed");
        return false;
    }

    jclass file_cls = env->FindClass("java/io/File");
    jmethodID file_init = env->GetMethodID(file_cls, "<init>", "(Ljava/lang/String;)V");
    jobject file = env->NewObject(file_cls, file_init, env->NewStringUTF(path));

    jclass fos_cls = env->FindClass("java/io/FileOutputStream");
    jobject fos = env->NewObject(fos_cls, env->GetMethodID(fos_cls, "<init>", "(Ljava/io/File;)V"), file);

    jbyteArray arr = env->NewByteArray((jsize)len);
    env->SetByteArrayRegion(arr, 0, (jsize)len, reinterpret_cast<const jbyte*>(data));

    jclass os_cls = env->FindClass("java/io/OutputStream");
    env->CallVoidMethod(fos, env->GetMethodID(os_cls, "write", "([B)V"), arr);
    env->CallVoidMethod(fos, env->GetMethodID(os_cls, "flush", "()V"));
    env->CallVoidMethod(fos, env->GetMethodID(os_cls, "close", "()V"));

    if (env->ExceptionCheck()) {
        env->ExceptionClear();
        snprintf(err, err_cap, "write exception");
        return false;
    }
    snprintf(err, err_cap, "%s", path);
    return true;
}

size_t buildDiagnosticJson(char* out, size_t cap, const MatchSnapshot& s, const HookDiagnostics& d) {
    if (!out || cap < 64) return 0;
    size_t pos = 0;

    char esc_sel[96];
    jsonEsc(s.last_hook_sel, esc_sel, sizeof(esc_sel));
    char esc_report[700];
    jsonEsc(d.report, esc_report, sizeof(esc_report));

    char log_buf[4096];
    logRingGet(log_buf, sizeof(log_buf));
    char esc_log[4500];
    jsonEsc(log_buf, esc_log, sizeof(esc_log));

    char menu_ptr[2048];
    char menu_inline[2048];
    exportMenuManagerMethods(menu_ptr, sizeof(menu_ptr), true);
    exportMenuManagerMethods(menu_inline, sizeof(menu_inline), false);

    char esc_menu_ptr[2200];
    char esc_menu_inline[2200];
    jsonEsc(menu_ptr, esc_menu_ptr, sizeof(esc_menu_ptr));
    jsonEsc(menu_inline, esc_menu_inline, sizeof(esc_menu_inline));

    uintptr_t base = getGameLibBase();

    appendf(out, cap, &pos, "{");
    appendf(out, cap, &pos, "\"build\":\"1013\",");
    appendf(out, cap, &pos, "\"lib_base\":\"0x%lx\",", (unsigned long)base);
    appendf(out, cap, &pos, "\"lib_loaded\":%s,", s.exports.lib_loaded ? "true" : "false");
    appendf(out, cap, &pos, "\"hooks_installed\":%s,", d.hooks_installed ? "true" : "false");
    appendf(out, cap, &pos, "\"egl_hooked\":%s,", s.egl_hooked ? "true" : "false");
    appendf(out, cap, &pos, "\"touch_hooks\":%s,", d.touch_hooks ? "true" : "false");
    appendf(out, cap, &pos, "\"class_table_ok\":%s,", d.class_table_ok ? "true" : "false");
    appendf(out, cap, &pos, "\"classes_scanned\":%d,", d.classes_scanned);
    appendf(out, cap, &pos, "\"watch_methods\":%d,", d.watch_methods_found);
    appendf(out, cap, &pos, "\"hooks_patched\":%d,", d.hooks_patched);
    appendf(out, cap, &pos, "\"hook_events\":%d,", d.hook_events);
    appendf(out, cap, &pos, "\"swap_frames\":%d,", s.swap_frames);
    appendf(out, cap, &pos, "\"frames_since_lib\":%d,", s.frames_since_lib);
    appendf(out, cap, &pos, "\"display\":{\"w\":%d,\"h\":%d},", s.display_w, s.display_h);
    appendf(out, cap, &pos, "\"telemetry\":{");
    appendf(out, cap, &pos, "\"source\":\"%s\",", dataSourceStr(s.data_source));
    appendf(out, cap, &pos, "\"last_sel\":\"%s\",", esc_sel);
    appendf(out, cap, &pos, "\"score_home\":%.3f,", s.score_home);
    appendf(out, cap, &pos, "\"score_away\":%.3f,", s.score_away);
    appendf(out, cap, &pos, "\"ball_x\":%.5f,", s.ball_x);
    appendf(out, cap, &pos, "\"ball_y\":%.5f,", s.ball_y);
    appendf(out, cap, &pos, "\"ball_valid\":%s,", s.ball_valid ? "true" : "false");
    appendf(out, cap, &pos, "\"body_count\":%d,", s.body_count);
    appendf(out, cap, &pos, "\"puck_estimate\":%d,", s.puck_estimate);
    appendf(out, cap, &pos, "\"shot_angle\":%.4f,", s.shot_angle);
    appendf(out, cap, &pos, "\"shot_power\":%.4f,", s.shot_power);
    appendf(out, cap, &pos, "\"internal_velocity\":%.4f,", s.exports.internal_velocity);
    appendf(out, cap, &pos, "\"physics_debug\":%d", s.exports.physics_debug);
    appendf(out, cap, &pos, "},");
    appendf(out, cap, &pos, "\"diag_report\":\"%s\",", esc_report);
    appendf(out, cap, &pos, "\"menu_methods_ptr_layout\":\"%s\",", esc_menu_ptr);
    appendf(out, cap, &pos, "\"menu_methods_inline_layout\":\"%s\",", esc_menu_inline);
    appendf(out, cap, &pos, "\"log_ring\":\"%s\"", esc_log);
    appendf(out, cap, &pos, "}");

    return pos;
}

const char* writeDiagnosticFile(const char* json, size_t len) {
    static char result[512];

    if (!json || len == 0) {
        snprintf(result, sizeof(result), "error: empty json");
        return result;
    }

    // Try direct fopen paths first (works on many devices with legacy storage)
    const char* paths[] = {
        "/sdcard/Download/ssm_research_dump.json",
        "/storage/emulated/0/Download/ssm_research_dump.json",
        nullptr,
    };
    for (int i = 0; paths[i]; i++) {
        FILE* f = fopen(paths[i], "wb");
        if (f) {
            fwrite(json, 1, len, f);
            fclose(f);
            snprintf(result, sizeof(result), "saved: %s", paths[i]);
            return result;
        }
    }

    char err[128];
    if (writeBytesJni("/sdcard/Download/ssm_research_dump.json", json, len, err, sizeof(err))) {
        snprintf(result, sizeof(result), "saved: %s", err);
        return result;
    }

    snprintf(result, sizeof(result), "error: could not write (%s)", err);
    return result;
}
