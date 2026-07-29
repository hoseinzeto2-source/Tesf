#include "telemetry.h"
#include "game_hooks.h"
#include "touch_input.h"
#include "log_ring.h"
#include "github_gist.h"
#include "diagnostic.h"

#include <algorithm>
#include <chrono>
#include <cmath>
#include <cstdint>
#include <cstdarg>
#include <cstdio>
#include <cstring>
#include <mutex>
#include <string>

#include "third_party/And64InlineHook.hpp"
#include "third_party/imgui.h"
#include "third_party/backends/imgui_impl_opengl3.h"

#include <android/log.h>
#include <dlfcn.h>
#include <EGL/egl.h>
#include <jni.h>

#define LOG_TAG "SSMResearchHUD"

static void hudLog(const char* fmt, ...) {
    char buf[512];
    va_list ap;
    va_start(ap, fmt);
    vsnprintf(buf, sizeof(buf), fmt, ap);
    va_end(ap);
    logRingAppend(buf);
    __android_log_print(ANDROID_LOG_INFO, LOG_TAG, "%s", buf);
}

#define LOGI(...) hudLog(__VA_ARGS__)

static EGLBoolean (*real_eglSwapBuffers)(EGLDisplay, EGLSurface) = nullptr;

static bool imgui_ready = false;
static bool egl_hooked = false;
static int swap_frames = 0;
static int frames_since_lib = 0;
static float ui_scale = 2.0f;
static MatchSnapshot cached_snap;
static std::mutex snap_mutex;
static auto last_time = std::chrono::steady_clock::now();

static HookDiagnostics diag_cache;
static bool show_diag = false;
static float hud_alpha = 0.90f;

static char g_github_token[128] = {};
static char g_upload_status[384] = {};
static char g_json_dump[12000] = {};

static float computeUiScale(int w, int h) {
    return std::clamp((float)std::min(w, h) / 480.f, 1.65f, 2.35f);
}

static void applyMobileStyle(float scale) {
    ImGuiStyle& style = ImGui::GetStyle();
    style.WindowRounding = 8.f * scale;
    style.FrameRounding = 5.f * scale;
    style.WindowPadding = ImVec2(14.f * scale, 12.f * scale);
    style.ItemSpacing = ImVec2(8.f * scale, 10.f * scale);
    style.Alpha = hud_alpha;
    style.ScaleAllSizes(scale);
}

static void trackLibFrames() {
    if (isGameLibLoaded()) {
        frames_since_lib++;
        pollGameHooks();
        pollTouchHooks();
    } else {
        if (frames_since_lib > 0) resetLiveScanState();
        frames_since_lib = 0;
    }
}

static void tryInitImGui(EGLDisplay dpy, EGLSurface surface) {
    if (imgui_ready) return;
    if (dpy == EGL_NO_DISPLAY || surface == EGL_NO_SURFACE) return;

    EGLint w = 0, h = 0;
    eglQuerySurface(dpy, surface, EGL_WIDTH, &w);
    eglQuerySurface(dpy, surface, EGL_HEIGHT, &h);
    if (w <= 0 || h <= 0) return;

    ui_scale = computeUiScale(w, h);
    IMGUI_CHECKVERSION();
    ImGui::CreateContext();
    ImGuiIO& io = ImGui::GetIO();
    io.IniFilename = nullptr;
    io.ConfigFlags |= ImGuiConfigFlags_IsTouchScreen;
    io.MouseDrawCursor = false;

    ImFontConfig font_cfg;
    font_cfg.SizePixels = std::clamp(18.f * ui_scale, 22.f, 32.f);
    io.Fonts->AddFontDefault(&font_cfg);

    ImGui::StyleColorsDark();
    applyMobileStyle(ui_scale);

    if (!ImGui_ImplOpenGL3_Init("#version 300 es")) {
        ImGui::DestroyContext();
        return;
    }
    imgui_ready = true;
    installTouchHooks();
}

static void refreshSnapshot(int w, int h) {
    std::lock_guard<std::mutex> lock(snap_mutex);
    cached_snap.display_w = w;
    cached_snap.display_h = h;
    cached_snap.swap_frames = swap_frames;
    cached_snap.frames_since_lib = frames_since_lib;
    cached_snap.egl_hooked = egl_hooked;
    cached_snap.hooks_installed = gameHooksInstalled();
    cached_snap.update_tick++;
    cached_snap.exports = readGameExportsCached();
    mergeHookSnapshot(cached_snap);
}

static void updateDisplaySize(EGLDisplay dpy, EGLSurface surface) {
    if (dpy == EGL_NO_DISPLAY || surface == EGL_NO_SURFACE) return;
    EGLint w = 0, h = 0;
    eglQuerySurface(dpy, surface, EGL_WIDTH, &w);
    eglQuerySurface(dpy, surface, EGL_HEIGHT, &h);
    if (w <= 0 || h <= 0) return;

    hudSetDisplaySize((float)w, (float)h);

    auto now = std::chrono::steady_clock::now();
    float dt = std::chrono::duration<float>(now - last_time).count();
    last_time = now;
    if (dt <= 0.f || dt > 0.5f) dt = 1.f / 60.f;

    ImGuiIO& io = ImGui::GetIO();
    io.DisplaySize = ImVec2((float)w, (float)h);
    io.DeltaTime = dt;

    trackLibFrames();
    refreshSnapshot(w, h);
}

static const char* dataSourceLabel(DataSource ds) {
    switch (ds) {
        case DataSource::HookShotOutcome: return "shot_outcome protobuf";
        case DataSource::HookGameStarted: return "game_started protobuf";
        case DataSource::HookNetworkReq: return "req.shot_outcome_data_";
        case DataSource::HookShotTaken: return "shot_taken field_state";
        default: return "waiting for match event";
    }
}

static void drawResearchHud() {
    ImGui::SetNextWindowPos(ImVec2(10.f, 10.f), ImGuiCond_FirstUseEver);
    ImGui::SetNextWindowBgAlpha(hud_alpha);

    ImGui::Begin("SSM HUD", nullptr,
                 ImGuiWindowFlags_AlwaysAutoResize | ImGuiWindowFlags_NoCollapse);

    std::lock_guard<std::mutex> lock(snap_mutex);
    const MatchSnapshot& s = cached_snap;

    ImGui::TextColored(ImVec4(0.35f, 1.f, 0.55f, 1.f), "LIVE MATCH (touch HUD panel to interact)");
    ImGui::Separator();

    ImGui::Text("tick %d  |  frame %d", s.update_tick, s.swap_frames);
    ImGui::Text("libgame: %s", s.exports.lib_loaded ? "loaded" : "waiting");
    ImGui::Text("objc: %s  |  touch: %s", s.hooks_installed ? "ON" : "...",
                touchHooksInstalled() ? "ON" : "waiting libgame");
    ImGui::Text("IMP hooks: %d  |  events: %d", s.hooks_patched, s.hook_events);
    ImGui::Text("classes: %d  |  watch methods: %d", gameClassesScanned(), gameWatchMethodsFound());
    if (s.last_hook_sel[0]) ImGui::Text("last: %s", s.last_hook_sel);

    ImGui::Separator();

    if (ImGui::Button("بررسی / Rescan Hooks", ImVec2(-1, 0))) {
        runHookDiagnostics(diag_cache);
        diag_cache.touch_hooks = touchHooksInstalled();
        show_diag = true;
        LOGI("diagnostic: %s", diag_cache.report);
    }

    ImGui::SliderFloat("HUD alpha", &hud_alpha, 0.35f, 1.f);
    if (ImGui::IsItemDeactivatedAfterEdit()) applyMobileStyle(ui_scale);

    if (show_diag) {
        ImGui::TextWrapped("%s", diag_cache.report);
        ImGui::Text("touch hooks: %s", diag_cache.touch_hooks ? "OK" : "NO");
    }

    ImGui::Separator();
    ImGui::TextColored(ImVec4(1.f, 0.85f, 0.3f, 1.f), "GitHub Remote Dump (cloud agent)");
    ImGui::InputText("GitHub PAT (gist)", g_github_token, sizeof(g_github_token),
                     ImGuiInputTextFlags_Password);

    if (ImGui::Button("Save dump to SD", ImVec2(-1, 0))) {
        HookDiagnostics dd = diag_cache;
        dd.touch_hooks = touchHooksInstalled();
        size_t n = buildDiagnosticJson(g_json_dump, sizeof(g_json_dump), s, dd);
        const char* wr = writeDiagnosticFile(g_json_dump, n);
        snprintf(g_upload_status, sizeof(g_upload_status), "%s (%zu bytes)", wr, n);
        LOGI("dump: %s", g_upload_status);
    }

    if (ImGui::Button("Upload Gist to GitHub", ImVec2(-1, 0))) {
        HookDiagnostics dd = diag_cache;
        dd.touch_hooks = touchHooksInstalled();
        size_t n = buildDiagnosticJson(g_json_dump, sizeof(g_json_dump), s, dd);
        std::string result = githubUploadGist(g_github_token, "ssm_research_dump.json", g_json_dump);
        snprintf(g_upload_status, sizeof(g_upload_status), "%s", result.c_str());
        LOGI("gist: %s", g_upload_status);
    }

    if (g_upload_status[0]) ImGui::TextWrapped("%s", g_upload_status);
    ImGui::TextWrapped("Fine-grained token: Gist write only. Share gist URL with Cursor agent.");

    ImGui::Separator();
    ImGui::Text("Source: %s", dataSourceLabel(s.data_source));

    if (s.score_home >= 0.f && s.score_away >= 0.f)
        ImGui::Text("Score: %d : %d", (int)s.score_home, (int)s.score_away);
    else
        ImGui::Text("Score: — (shot_outcome event)");

    if (s.ball_valid)
        ImGui::Text("Ball  X: %.4f  Y: %.4f", s.ball_x, s.ball_y);
    else
        ImGui::Text("Ball: — (field_state)");

    if (s.body_count > 0) {
        ImGui::Text("field_state: %d bodies", s.body_count);
        ImGui::Text("pucks (excl. ball): %d", s.puck_estimate);
    }

    if (s.shot_angle >= 0.f || s.shot_power >= 0.f) {
        ImGui::Text("Last shot: angle %.3f  power %.3f",
                    s.shot_angle >= 0.f ? s.shot_angle : 0.f,
                    s.shot_power >= 0.f ? s.shot_power : 0.f);
    }

    ImGui::Separator();
    if (s.exports.lib_loaded) {
        ImGui::Text("sInternalVelocity: %.4f", s.exports.internal_velocity);
        ImGui::Text("physics debug: %d", s.exports.physics_debug);
    }

    ImGui::Separator();
    ImGui::TextWrapped("Touch inside this window for buttons/slider. Game input blocked only on HUD.");

    ImVec2 p = ImGui::GetWindowPos();
    ImVec2 sz = ImGui::GetWindowSize();
    hudUpdateWindowRect(p.x, p.y, p.x + sz.x, p.y + sz.y);

    ImGui::End();
}

static void renderImGuiFrame(EGLDisplay dpy, EGLSurface surface) {
    if (dpy == EGL_NO_DISPLAY || surface == EGL_NO_SURFACE) return;
    tryInitImGui(dpy, surface);
    if (!imgui_ready) return;

    updateDisplaySize(dpy, surface);

    ImGui_ImplOpenGL3_NewFrame();
    touchApplyPendingEvents();
    ImGui::NewFrame();
    drawResearchHud();
    ImGui::Render();
    ImGui_ImplOpenGL3_RenderDrawData(ImGui::GetDrawData());
}

static EGLBoolean hook_eglSwapBuffers(EGLDisplay dpy, EGLSurface surface) {
    swap_frames++;
    renderImGuiFrame(dpy, surface);
    return real_eglSwapBuffers ? real_eglSwapBuffers(dpy, surface) : EGL_FALSE;
}

static void installEglHook() {
    if (egl_hooked) return;
    void* egl = dlopen("libEGL.so", RTLD_NOW);
    if (!egl) return;
    void* sym = dlsym(egl, "eglSwapBuffers");
    if (!sym) return;
    A64HookFunction(sym, (void*)hook_eglSwapBuffers, (void**)&real_eglSwapBuffers);
    egl_hooked = true;
}

extern "C" JNIEXPORT jint JNI_OnLoad(JavaVM* vm, void* reserved) {
    (void)reserved;
    logRingInit();
    githubSetJavaVm(vm);
    diagnosticSetJavaVm(vm);
    LOGI("ssm_research_hud: github dump + cocotron scan");
    installEglHook();
    installGameHooks();
    return JNI_VERSION_1_6;
}
