#include "telemetry.h"

#include <algorithm>
#include <chrono>
#include <cmath>
#include <cstdint>
#include <mutex>

#include "third_party/And64InlineHook.hpp"
#include "third_party/imgui.h"
#include "third_party/backends/imgui_impl_opengl3.h"

#include <android/log.h>
#include <dlfcn.h>
#include <EGL/egl.h>
#include <jni.h>

#define LOG_TAG "SSMResearchHUD"
#define LOGI(...) __android_log_print(ANDROID_LOG_INFO, LOG_TAG, __VA_ARGS__)
#define LOGE(...) __android_log_print(ANDROID_LOG_ERROR, LOG_TAG, __VA_ARGS__)

static const int kLibStableFrames = 90; // ~1.5s after libgame loads before heap scan

static EGLBoolean (*real_eglSwapBuffers)(EGLDisplay, EGLSurface) = nullptr;

static bool imgui_ready = false;
static bool egl_hooked = false;
static int swap_frames = 0;
static int frames_since_lib = 0;
static float ui_scale = 2.0f;
static MatchSnapshot cached_snap;
static std::mutex snap_mutex;
static auto last_time = std::chrono::steady_clock::now();

static float computeUiScale(int w, int h) {
    const float short_edge = (float)std::min(w, h);
    return std::clamp(short_edge / 480.f, 1.65f, 2.35f);
}

static void applyMobileStyle(float scale) {
    ImGuiStyle& style = ImGui::GetStyle();
    style.WindowRounding = 8.f * scale;
    style.FrameRounding = 5.f * scale;
    style.WindowPadding = ImVec2(14.f * scale, 12.f * scale);
    style.ItemSpacing = ImVec2(8.f * scale, 10.f * scale);
    style.ItemInnerSpacing = ImVec2(6.f * scale, 5.f * scale);
    style.ScrollbarSize = 18.f * scale;
    style.Alpha = 0.90f;
    style.ScaleAllSizes(scale);
}

static void trackLibFrames() {
    if (isGameLibLoaded()) {
        frames_since_lib++;
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

    ImFontConfig font_cfg;
    font_cfg.SizePixels = std::clamp(18.f * ui_scale, 22.f, 32.f);
    io.Fonts->AddFontDefault(&font_cfg);
    io.FontGlobalScale = 1.0f;

    ImGui::StyleColorsDark();
    applyMobileStyle(ui_scale);

    if (!ImGui_ImplOpenGL3_Init("#version 300 es")) {
        LOGE("ImGui_ImplOpenGL3_Init failed");
        ImGui::DestroyContext();
        return;
    }

    imgui_ready = true;
    LOGI("ImGui ready GLES3 scale=%.2f font=%.0f display=%dx%d", ui_scale, font_cfg.SizePixels, w, h);
}

static void refreshSnapshot(int w, int h) {
    const bool allowScan = frames_since_lib >= kLibStableFrames;

    std::lock_guard<std::mutex> lock(snap_mutex);
    cached_snap.display_w = w;
    cached_snap.display_h = h;
    cached_snap.swap_frames = swap_frames;
    cached_snap.frames_since_lib = frames_since_lib;
    cached_snap.egl_hooked = egl_hooked;
    cached_snap.update_tick++;

    cached_snap.exports = readGameExportsCached();
    cached_snap.live_scan_active = allowScan && cached_snap.exports.lib_loaded;

    if (cached_snap.live_scan_active) {
        applyRotatingBodyScan(cached_snap, 768 * 1024);
        if (cached_snap.update_tick % 20 == 0) {
            applyRotatingScoreScan(cached_snap, 256 * 1024);
        }
    }
}

static void updateDisplaySize(EGLDisplay dpy, EGLSurface surface) {
    if (dpy == EGL_NO_DISPLAY || surface == EGL_NO_SURFACE) return;
    EGLint w = 0, h = 0;
    eglQuerySurface(dpy, surface, EGL_WIDTH, &w);
    eglQuerySurface(dpy, surface, EGL_HEIGHT, &h);
    if (w <= 0 || h <= 0) return;

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

static void drawResearchHud() {
    const float pad = 10.f;

    ImGui::SetNextWindowPos(ImVec2(pad, pad), ImGuiCond_Always);
    ImGui::SetNextWindowBgAlpha(0.88f);

    const ImGuiWindowFlags flags =
        ImGuiWindowFlags_NoCollapse | ImGuiWindowFlags_AlwaysAutoResize | ImGuiWindowFlags_NoMove;

    ImGui::Begin("SSM HUD", nullptr, flags);

    std::lock_guard<std::mutex> lock(snap_mutex);
    const MatchSnapshot& s = cached_snap;

    ImGui::PushStyleColor(ImGuiCol_Text, ImVec4(0.35f, 1.f, 0.55f, 1.f));
    ImGui::TextUnformatted("READ-ONLY RESEARCH HUD");
    ImGui::PopStyleColor();
    ImGui::Separator();

    ImGui::Text("Display: %d x %d", s.display_w, s.display_h);
    ImGui::Text("Live tick: %d  |  frame: %d", s.update_tick, s.swap_frames);
    ImGui::Text("libgame: %s", s.exports.lib_loaded ? "loaded" : "not yet");
  if (!s.exports.lib_loaded) {
        ImGui::TextColored(ImVec4(1.f, 0.75f, 0.3f, 1.f), "Enter a match for live data");
    } else if (!s.live_scan_active) {
        ImGui::TextColored(ImVec4(1.f, 0.75f, 0.3f, 1.f),
                           "Live scan in ~%ds (match loading...)",
                           (kLibStableFrames - s.frames_since_lib + 59) / 60);
    } else {
        ImGui::TextColored(ImVec4(0.4f, 1.f, 0.5f, 1.f),
                           "LIVE  (~60 Hz physics, rotating scan #%d)", s.scan_pass);
    }

    ImGui::Separator();
    ImGui::TextUnformatted("MATCH");
    if (s.score_home >= 0.f && s.score_away >= 0.f)
        ImGui::Text("Score (est.): %d : %d", (int)s.score_home, (int)s.score_away);
    else
        ImGui::Text("Score: see game HUD (1:0 etc.)");

    if (s.ball_valid) {
        ImGui::Text("Ball X: %.4f  Y: %.4f", s.ball_x, s.ball_y);
        ImGui::Text("Ball Vx: %.4f  Vy: %.4f", s.ball_vx, s.ball_vy);
    } else if (s.live_scan_active) {
        ImGui::TextColored(ImVec4(1.f, 0.5f, 0.4f, 1.f), "Ball: scanning...");
    } else {
        ImGui::Text("Ball: waiting for match");
    }

    if (s.live_scan_active) {
        ImGui::Text("Pucks (est.): %d", s.puck_estimate);
        ImGui::Text("Bodies: %d", s.body_count);
    }

    ImGui::Separator();
    ImGui::TextUnformatted("PHYSICS");
    if (s.exports.lib_loaded) {
        ImGui::Text("sInternalVelocity: %.4f", s.exports.internal_velocity);
        ImGui::Text("physics_debug: %d  diag: %d", s.exports.physics_debug, s.exports.physics_diag);
    } else {
        ImGui::Text("Physics exports: N/A");
    }
    ImGui::TextUnformatted("Field: 2D (X,Y only)");

    ImGui::Separator();
    ImGui::TextWrapped("Read-only overlay. No shot injection.");

    ImGui::End();
}

static void renderImGuiFrame(EGLDisplay dpy, EGLSurface surface) {
    if (dpy == EGL_NO_DISPLAY || surface == EGL_NO_SURFACE) return;

    tryInitImGui(dpy, surface);
    if (!imgui_ready) return;

    updateDisplaySize(dpy, surface);

    ImGui_ImplOpenGL3_NewFrame();
    ImGui::NewFrame();
    drawResearchHud();
    ImGui::Render();
    ImGui_ImplOpenGL3_RenderDrawData(ImGui::GetDrawData());
}

static EGLBoolean hook_eglSwapBuffers(EGLDisplay dpy, EGLSurface surface) {
    swap_frames++;
    renderImGuiFrame(dpy, surface);
    if (!real_eglSwapBuffers) return EGL_FALSE;
    return real_eglSwapBuffers(dpy, surface);
}

static void installEglHook() {
    if (egl_hooked) return;

    void* egl = dlopen("libEGL.so", RTLD_NOW);
    if (!egl) {
        LOGE("dlopen libEGL failed");
        return;
    }

    void* sym = dlsym(egl, "eglSwapBuffers");
    if (!sym) {
        LOGE("eglSwapBuffers missing");
        return;
    }

    A64HookFunction(sym, (void*)hook_eglSwapBuffers, (void**)&real_eglSwapBuffers);
    egl_hooked = true;
    LOGI("eglSwapBuffers hooked");

    void* sym2 = dlsym(egl, "eglSwapBuffersWithDamageKHR");
    if (sym2 && !real_eglSwapBuffers) {
        A64HookFunction(sym2, (void*)hook_eglSwapBuffers, (void**)&real_eglSwapBuffers);
        LOGI("eglSwapBuffersWithDamageKHR hooked");
    }
}

extern "C" JNIEXPORT jint JNI_OnLoad(JavaVM* vm, void* reserved) {
    (void)vm;
    (void)reserved;
    LOGI("ssm_research_hud loaded (egl HUD + deferred live read)");
    installEglHook();
    return JNI_VERSION_1_6;
}
