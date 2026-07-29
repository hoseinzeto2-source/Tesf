#include "touch_input.h"

#include <stdint.h>
#include <cstdint>

#include "third_party/And64InlineHook.hpp"
#include "third_party/imgui.h"

#include <android/log.h>
#include <dlfcn.h>
#include <jni.h>

#include <atomic>
#include <mutex>
#include <vector>

#define LOG_TAG "SSMResearchHUD"
#define LOGI(...) __android_log_print(ANDROID_LOG_INFO, LOG_TAG, __VA_ARGS__)

static const char* kGameLib = "libgame-SSM-GooglePlay-Gold-Release-Module-1013.so";

using TouchBeginFn = void (*)(void*, void*, int32_t, float, float, uint8_t, uint8_t);
using TouchEndFn = void (*)(void*, void*, int32_t, float, float, uint8_t, uint8_t);
using TouchMoveFn = void (*)(void*, void*, void*, void*, void*, uint8_t, uint8_t);

static TouchBeginFn real_touch_begin = nullptr;
static TouchEndFn real_touch_end = nullptr;
static TouchMoveFn real_touch_move = nullptr;
static bool g_touch_hooks_installed = false;

static float g_disp_w = 0.f;
static float g_disp_h = 0.f;
static ImVec2 g_hud_min{0, 0};
static ImVec2 g_hud_max{0, 0};

static std::mutex g_touch_mutex;
static std::atomic<bool> g_active_steal{false};
static bool g_logged_touch = false;

enum class TouchEvtType : uint8_t { Down, Move, Up };

struct QueuedTouch {
    float x = 0.f;
    float y = 0.f;
    TouchEvtType type = TouchEvtType::Move;
};

static std::vector<QueuedTouch> g_pending;

void hudSetDisplaySize(float w, float h) {
    g_disp_w = w;
    g_disp_h = h;
    if (g_hud_max.x <= g_hud_min.x || g_hud_max.y <= g_hud_min.y) {
        g_hud_min = ImVec2(0.f, 0.f);
        g_hud_max = ImVec2(w * 0.98f, h * 0.75f);
    }
}

void hudUpdateWindowRect(float min_x, float min_y, float max_x, float max_y) {
    g_hud_min = ImVec2(min_x, min_y);
    g_hud_max = ImVec2(max_x, max_y);
}

static bool pointInHud(float x, float y) {
    if (g_hud_max.x <= g_hud_min.x || g_hud_max.y <= g_hud_min.y) return false;
    constexpr float pad = 16.f;
    return x >= g_hud_min.x - pad && x <= g_hud_max.x + pad && y >= g_hud_min.y - pad &&
           y <= g_hud_max.y + pad;
}

static void queueTouch(float x, float y, TouchEvtType type) {
    std::lock_guard<std::mutex> lock(g_touch_mutex);
    g_pending.push_back({x, y, type});
    if (g_pending.size() > 64) g_pending.erase(g_pending.begin(), g_pending.begin() + 32);
}

void touchApplyPendingEvents() {
    if (!ImGui::GetCurrentContext()) return;

    std::vector<QueuedTouch> batch;
    {
        std::lock_guard<std::mutex> lock(g_touch_mutex);
        batch.swap(g_pending);
    }

    ImGuiIO& io = ImGui::GetIO();
    for (const QueuedTouch& e : batch) {
        io.AddMouseSourceEvent(ImGuiMouseSource_TouchScreen);
        io.AddMousePosEvent(e.x, e.y);
        if (e.type == TouchEvtType::Down) io.AddMouseButtonEvent(0, true);
        if (e.type == TouchEvtType::Up) io.AddMouseButtonEvent(0, false);
    }
}

static void handleTouch(float x, float y, TouchEvtType type) {
    if (!g_logged_touch) {
        g_logged_touch = true;
        LOGI("touch: first event %.0f,%.0f type=%d hud=[%.0f-%.0f, %.0f-%.0f]", x, y, (int)type,
             g_hud_min.x, g_hud_max.x, g_hud_min.y, g_hud_max.y);
    }

    const bool in_hud = pointInHud(x, y);

    if (type == TouchEvtType::Down) {
        g_active_steal = in_hud;
        if (g_active_steal) queueTouch(x, y, TouchEvtType::Down);
        return;
    }

    if (type == TouchEvtType::Move) {
        if (g_active_steal || in_hud) {
            g_active_steal = true;
            queueTouch(x, y, TouchEvtType::Move);
        }
        return;
    }

    if (type == TouchEvtType::Up) {
        if (g_active_steal) {
            queueTouch(x, y, TouchEvtType::Up);
            g_active_steal = false;
        }
    }
}

static bool readFirstTouch(JNIEnv* env, void* x_arr, void* y_arr, float& x, float& y) {
    if (!env || !x_arr || !y_arr) return false;
    auto* ax = static_cast<jfloatArray>(x_arr);
    auto* ay = static_cast<jfloatArray>(y_arr);
    if (env->GetArrayLength(ax) < 1 || env->GetArrayLength(ay) < 1) return false;
    env->GetFloatArrayRegion(ax, 0, 1, &x);
    env->GetFloatArrayRegion(ay, 0, 1, &y);
    return true;
}

static void hook_touch_begin(void* env, void* cls, int32_t id, float x, float y, uint8_t a, uint8_t b) {
    (void)cls;
    (void)id;
    (void)a;
    (void)b;
    handleTouch(x, y, TouchEvtType::Down);
    if (!g_active_steal && real_touch_begin) real_touch_begin(env, cls, id, x, y, a, b);
}

static void hook_touch_move(void* env, void* cls, void* id_arr, void* x_arr, void* y_arr, uint8_t a,
                            uint8_t b) {
    (void)cls;
    (void)id_arr;
    (void)a;
    (void)b;
    float x = 0.f, y = 0.f;
    if (readFirstTouch(static_cast<JNIEnv*>(env), x_arr, y_arr, x, y)) {
        handleTouch(x, y, TouchEvtType::Move);
    }
    if (!g_active_steal && real_touch_move) real_touch_move(env, cls, id_arr, x_arr, y_arr, a, b);
}

static void hook_touch_end(void* env, void* cls, int32_t id, float x, float y, uint8_t a, uint8_t b) {
    (void)cls;
    (void)id;
    (void)a;
    (void)b;
    handleTouch(x, y, TouchEvtType::Up);
    if (!g_active_steal && real_touch_end) real_touch_end(env, cls, id, x, y, a, b);
}

static bool installTouchHooksOnce() {
    if (g_touch_hooks_installed) return true;
    void* lib = dlopen(kGameLib, RTLD_NOLOAD);
    if (!lib) return false;

    void* begin_sym = dlsym(lib, "Java_com_miniclip_input_MCInput_nativeTouchesBegin");
    void* move_sym = dlsym(lib, "Java_com_miniclip_input_MCInput_nativeTouchesMove");
    void* end_sym = dlsym(lib, "Java_com_miniclip_input_MCInput_nativeTouchesEnd");
    if (!begin_sym || !end_sym) return false;

    A64HookFunction(begin_sym, (void*)hook_touch_begin, (void**)&real_touch_begin);
    A64HookFunction(end_sym, (void*)hook_touch_end, (void**)&real_touch_end);
    if (move_sym) A64HookFunction(move_sym, (void*)hook_touch_move, (void**)&real_touch_move);

    g_touch_hooks_installed = true;
    LOGI("touch: MCInput hooks installed begin=%p move=%p end=%p", begin_sym, move_sym, end_sym);
    return true;
}

void installTouchHooks() {
    installTouchHooksOnce();
}

void pollTouchHooks() {
    installTouchHooksOnce();
}

bool touchHooksInstalled() {
    return g_touch_hooks_installed;
}
