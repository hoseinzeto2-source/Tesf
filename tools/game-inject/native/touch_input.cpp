#include "touch_input.h"

#include <stdint.h>
#include <cstdint>

#include "third_party/And64InlineHook.hpp"
#include "third_party/imgui.h"

#include <android/log.h>
#include <dlfcn.h>
#include <jni.h>
#include <cstdint>
#include <mutex>

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
static std::mutex g_touch_mutex;

static float g_disp_w = 0.f;
static float g_disp_h = 0.f;
static ImVec2 g_hud_min{0, 0};
static ImVec2 g_hud_max{0, 0};
static bool g_steal_touch = false;

void hudSetDisplaySize(float w, float h) {
    g_disp_w = w;
    g_disp_h = h;
}

void hudUpdateWindowRect(float min_x, float min_y, float max_x, float max_y) {
    g_hud_min = ImVec2(min_x, min_y);
    g_hud_max = ImVec2(max_x, max_y);
}

static float flipY(float y) {
    if (g_disp_h <= 0.f) return y;
    return g_disp_h - y;
}

static bool pointInHud(float x, float y_top_origin) {
    if (g_hud_max.x <= g_hud_min.x || g_hud_max.y <= g_hud_min.y) return false;
    return x >= g_hud_min.x && x <= g_hud_max.x && y_top_origin >= g_hud_min.y && y_top_origin <= g_hud_max.y;
}

static void feedImGui(float x, float y_android, bool down, bool is_move) {
    if (!ImGui::GetCurrentContext()) return;
    ImGuiIO& io = ImGui::GetIO();
    const float iy = flipY(y_android);
    io.AddMouseSourceEvent(ImGuiMouseSource_TouchScreen);
    io.AddMousePosEvent(x, iy);
    if (!is_move) io.AddMouseButtonEvent(0, down);
}

static void handleTouch(float x, float y, bool down, bool is_move) {
    const float iy = flipY(y);
    const bool in_hud = pointInHud(x, iy);

    if (!is_move) {
        g_steal_touch = in_hud;
        if (g_steal_touch) feedImGui(x, y, down, false);
        return;
    }

    if (g_steal_touch || in_hud) {
        g_steal_touch = true;
        feedImGui(x, y, true, true);
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
    (void)id;
    (void)a;
    (void)b;
    std::lock_guard<std::mutex> lock(g_touch_mutex);
    handleTouch(x, y, true, false);
    if (!g_steal_touch && real_touch_begin) real_touch_begin(env, cls, id, x, y, a, b);
}

static void hook_touch_move(void* env, void* cls, void* id_arr, void* x_arr, void* y_arr, uint8_t a,
                            uint8_t b) {
    (void)cls;
    (void)id_arr;
    (void)a;
    (void)b;
    std::lock_guard<std::mutex> lock(g_touch_mutex);
    float x = 0.f, y = 0.f;
    if (g_steal_touch && readFirstTouch(static_cast<JNIEnv*>(env), x_arr, y_arr, x, y)) {
        handleTouch(x, y, true, true);
        return;
    }
    if (real_touch_move) real_touch_move(env, cls, id_arr, x_arr, y_arr, a, b);
}

static void hook_touch_end(void* env, void* cls, int32_t id, float x, float y, uint8_t a, uint8_t b) {
    (void)id;
    (void)a;
    (void)b;
    std::lock_guard<std::mutex> lock(g_touch_mutex);
    if (g_steal_touch) {
        feedImGui(x, y, false, false);
        g_steal_touch = false;
        return;
    }
    if (real_touch_end) real_touch_end(env, cls, id, x, y, a, b);
}

static bool installTouchHooksOnce() {
    if (g_touch_hooks_installed) return true;
    void* lib = dlopen(kGameLib, RTLD_NOLOAD);
    if (!lib) return false;

    void* begin_sym = dlsym(lib, "Java_com_miniclip_input_MCInput_nativeTouchesBegin");
    void* move_sym = dlsym(lib, "Java_com_miniclip_input_MCInput_nativeTouchesMove");
    void* end_sym = dlsym(lib, "Java_com_miniclip_input_MCInput_nativeTouchesEnd");
    if (!begin_sym || !end_sym) {
        begin_sym = dlsym(lib, "Java_com_miniclip_windowmanager_NativeWindowRenderer_nativeTouchesBegin");
        move_sym = dlsym(lib, "Java_com_miniclip_windowmanager_NativeWindowRenderer_nativeTouchesMove");
        end_sym = dlsym(lib, "Java_com_miniclip_windowmanager_NativeWindowRenderer_nativeTouchesEnd");
    }
    if (!begin_sym || !end_sym) return false;

    A64HookFunction(begin_sym, (void*)hook_touch_begin, (void**)&real_touch_begin);
    A64HookFunction(end_sym, (void*)hook_touch_end, (void**)&real_touch_end);
    if (move_sym) A64HookFunction(move_sym, (void*)hook_touch_move, (void**)&real_touch_move);
    g_touch_hooks_installed = true;
    LOGI("touch: hooks active (begin/move/end)");
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
