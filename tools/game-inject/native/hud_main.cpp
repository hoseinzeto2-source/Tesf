#include "telemetry.h"
#include "third_party/And64InlineHook.hpp"
#include "third_party/imgui.h"
#include "third_party/backends/imgui_impl_opengl3.h"

#include <android/log.h>
#include <dlfcn.h>
#include <EGL/egl.h>
#include <jni.h>
#include <mutex>
#include <pthread.h>
#include <unistd.h>
#include <chrono>

#define LOG_TAG "SSMResearchHUD"
#define LOGI(...) __android_log_print(ANDROID_LOG_INFO, LOG_TAG, __VA_ARGS__)
#define LOGE(...) __android_log_print(ANDROID_LOG_ERROR, LOG_TAG, __VA_ARGS__)

static const char* kGameLib = "libgame-SSM-GooglePlay-Gold-Release-Module-1013.so";
static const char* kChoreographerSym =
    "Java_com_miniclip_windowmanager_NativeWindowRenderer_onChoreographer";

static const int kTelemetryStartFrames = 90;   // ~1.5s menu — avoid heap scan during match start tap
static const int kChoreographerDeferFrames = 120; // defer game-lib hook until menu is stable

static EGLBoolean (*real_eglSwapBuffers)(EGLDisplay, EGLSurface) = nullptr;
static void (*real_onChoreographer)(JNIEnv*, jclass, jlong) = nullptr;

static bool imgui_ready = false;
static bool choreographer_hooked = false;
static bool egl_hooked = false;
static int swap_frames = 0;
static int choreo_frames = 0;
static std::mutex data_mutex;
static MatchSnapshot cached_snap;
static EGLDisplay cached_dpy = EGL_NO_DISPLAY;
static EGLSurface cached_surf = EGL_NO_SURFACE;
static auto last_time = std::chrono::steady_clock::now();

static void tryInitImGui(EGLDisplay dpy, EGLSurface surface) {
    if (imgui_ready) return;
    if (dpy == EGL_NO_DISPLAY || surface == EGL_NO_SURFACE) return;

    IMGUI_CHECKVERSION();
    ImGui::CreateContext();
    ImGui::StyleColorsDark();
    ImGuiStyle& style = ImGui::GetStyle();
    style.WindowRounding = 6.f;
    style.Alpha = 0.94f;
    ImGui::GetStyle().ScaleAllSizes(2.0f);

    if (!ImGui_ImplOpenGL3_Init("#version 300 es")) {
        LOGE("ImGui_ImplOpenGL3_Init failed");
        return;
    }
    imgui_ready = true;
    LOGI("ImGui ready (GLES3)");
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
    io.FontGlobalScale = 1.15f;
}

static void refreshTelemetry(int w, int h) {
    MatchSnapshot snap = buildMatchSnapshot(w, h, choreographer_hooked, egl_hooked, swap_frames);
    snap.frame_counter = choreo_frames;
    std::lock_guard<std::mutex> lock(data_mutex);
    cached_snap = snap;
}

static void drawResearchHud() {
    ImGui::SetNextWindowPos(ImVec2(8, 8), ImGuiCond_Always);
    ImGui::SetNextWindowSize(ImVec2(400, 520), ImGuiCond_FirstUseEver);
    ImGui::SetNextWindowBgAlpha(0.88f);

    ImGui::Begin("SSM Match HUD [Research]",
                 nullptr,
                 ImGuiWindowFlags_NoCollapse | ImGuiWindowFlags_AlwaysAutoResize);

    std::lock_guard<std::mutex> lock(data_mutex);
    const MatchSnapshot& s = cached_snap;

    ImGui::TextColored(ImVec4(0.3f, 1.f, 0.5f, 1.f), "READ-ONLY | Bug Bounty Lab");
    ImGui::Separator();

    ImGui::Text("HUD frames: %d  |  swap: %d", s.frame_counter, s.swap_frames);
    ImGui::Text("Hooks: choreo=%s egl=%s",
                s.choreographer_hooked ? "OK" : "no",
                s.egl_hooked ? "OK" : "no");
    ImGui::Text("Display: %dx%d", s.display_w, s.display_h);

    ImGui::Separator();
    ImGui::Text("MATCH");
    if (s.score_home >= 0 && s.score_away >= 0)
        ImGui::Text("Score (heap est.): %d : %d", (int)s.score_home, (int)s.score_away);
    else
        ImGui::TextColored(ImVec4(1.f, 0.7f, 0.2f, 1.f), "Score: online/server (not in client)");

  if (s.ball_valid) {
        ImGui::Text("Ball X: %.4f  Y: %.4f", s.ball_x, s.ball_y);
        ImGui::Text("Ball Vx: %.4f  Vy: %.4f", s.ball_vx, s.ball_vy);
    } else {
        ImGui::TextColored(ImVec4(1.f, 0.4f, 0.4f, 1.f), "Ball: enter a match first");
    }
    ImGui::Text("Pucks on field (est.): %d", s.puck_estimate);
    ImGui::Text("Bodies tracked: %d", s.body_count);

    ImGui::Separator();
    ImGui::Text("PHYSICS");
    ImGui::Text("sInternalVelocity: %.4f", s.internal_velocity);
    ImGui::Text("physics_debug: %d", s.physics_debug);
    ImGui::Text("Field: 2D (no Z axis)");

    ImGui::Separator();
    ImGui::TextWrapped("Educational overlay only. No shot injection.");
    ImGui::End();
}

static void renderImGuiFrame(EGLDisplay dpy, EGLSurface surface) {
    if (dpy == EGL_NO_DISPLAY || surface == EGL_NO_SURFACE) return;

    cached_dpy = dpy;
    cached_surf = surface;
    tryInitImGui(dpy, surface);
    if (!imgui_ready) return;

    updateDisplaySize(dpy, surface);
    EGLint w = (EGLint)ImGui::GetIO().DisplaySize.x;
    EGLint h = (EGLint)ImGui::GetIO().DisplaySize.y;

    if (swap_frames >= kTelemetryStartFrames && swap_frames % 10 == 0) refreshTelemetry(w, h);

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

static void hook_onChoreographer(JNIEnv* env, jclass clazz, jlong frameTime) {
    choreo_frames++;
    EGLDisplay dpy = eglGetCurrentDisplay();
    EGLSurface surf = eglGetCurrentSurface(EGL_DRAW);
    if (dpy != EGL_NO_DISPLAY && surf != EGL_NO_SURFACE) {
        cached_dpy = dpy;
        cached_surf = surf;
    }
    if (real_onChoreographer) real_onChoreographer(env, clazz, frameTime);
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

static void installChoreographerHook() {
    if (choreographer_hooked) return;
    void* game = dlopen(kGameLib, RTLD_NOLOAD);
    if (!game) return;

    void* sym = dlsym(game, kChoreographerSym);
    if (!sym) {
        LOGE("onChoreographer symbol not found");
        return;
    }
    A64HookFunction(sym, (void*)hook_onChoreographer, (void**)&real_onChoreographer);
    choreographer_hooked = true;
    LOGI("NativeWindowRenderer.onChoreographer hooked");
}

static void* delayedHookThread(void*) {
    for (int i = 0; i < 180; i++) {
        installEglHook();
        if (swap_frames >= kChoreographerDeferFrames) {
            installChoreographerHook();
        }
        if (egl_hooked && (choreographer_hooked || i >= 40)) break;
        usleep(500000);
    }
    if (!choreographer_hooked) LOGI("choreographer hook skipped/deferred (egl HUD only)");
    if (!egl_hooked) LOGE("egl hook never installed");
    return nullptr;
}

static void startDelayedHooks() {
    installEglHook();
    pthread_t t;
    pthread_create(&t, nullptr, delayedHookThread, nullptr);
    pthread_detach(t);
}

extern "C" JNIEXPORT jint JNI_OnLoad(JavaVM* vm, void* reserved) {
    (void)vm;
    (void)reserved;
    LOGI("ssm_research_hud loaded");
    startDelayedHooks();
    return JNI_VERSION_1_6;
}
