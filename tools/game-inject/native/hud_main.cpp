#include "telemetry.h"
#include "third_party/And64InlineHook.hpp"
#include "third_party/imgui.h"
#include "third_party/backends/imgui_impl_opengl3.h"

#include <android/log.h>
#include <dlfcn.h>
#include <EGL/egl.h>
#include <jni.h>
#include <mutex>

#define LOG_TAG "SSMResearchHUD"
#define LOGI(...) __android_log_print(ANDROID_LOG_INFO, LOG_TAG, __VA_ARGS__)

static EGLBoolean (*real_eglSwapBuffers)(EGLDisplay, EGLSurface) = nullptr;
static bool imgui_ready = false;
static int frame_counter = 0;
static std::vector<BodySample> cached_bodies;
static float cached_velocity = 0.f;
static int cached_debug = 0;
static std::mutex data_mutex;

static void refreshTelemetry() {
    if (frame_counter++ % 15 != 0) return;
    auto bodies = scanBodies(12);
    float vel = readInternalVelocity();
    int dbg = readPhysicsDebug();
    std::lock_guard<std::mutex> lock(data_mutex);
    cached_bodies = std::move(bodies);
    cached_velocity = vel;
    cached_debug = dbg;
}

static void drawResearchHud() {
    ImGui::SetNextWindowPos(ImVec2(12, 12), ImGuiCond_FirstUseEver);
    ImGui::SetNextWindowSize(ImVec2(360, 420), ImGuiCond_FirstUseEver);
    ImGui::Begin("SSM Research HUD (Bug Bounty / Education)",
                 nullptr,
                 ImGuiWindowFlags_NoCollapse);

    ImGui::TextColored(ImVec4(0.4f, 0.8f, 1.f, 1.f), "READ-ONLY — University RE Lab");
    ImGui::Separator();

    std::lock_guard<std::mutex> lock(data_mutex);
    ImGui::Text("Physics debug: %d", cached_debug);
    ImGui::Text("sInternalVelocity: %.4f", cached_velocity);
    ImGui::Text("Bodies (norm XY): %d", (int)cached_bodies.size());
    ImGui::Text("Z axis: N/A (2D field)");

  if (ImGui::BeginTable("bodies", 5, ImGuiTableFlags_Borders | ImGuiTableFlags_RowBg)) {
        ImGui::TableSetupColumn("X");
        ImGui::TableSetupColumn("Y");
        ImGui::TableSetupColumn("Vx");
        ImGui::TableSetupColumn("Vy");
        ImGui::TableSetupColumn("Spd");
        ImGui::TableHeadersRow();
        for (const auto& b : cached_bodies) {
            ImGui::TableNextRow();
            ImGui::TableSetColumnIndex(0);
            ImGui::Text("%.3f", b.x);
            ImGui::TableSetColumnIndex(1);
            ImGui::Text("%.3f", b.y);
            ImGui::TableSetColumnIndex(2);
            ImGui::Text("%.3f", b.vx);
            ImGui::TableSetColumnIndex(3);
            ImGui::Text("%.3f", b.vy);
            ImGui::TableSetColumnIndex(4);
            ImGui::Text("%.3f", b.speed);
        }
        ImGui::EndTable();
    }

    ImGui::Separator();
    ImGui::TextWrapped(
        "Educational overlay. No shot injection. Online scores remain server-side.");
    ImGui::End();
}

static EGLBoolean hook_eglSwapBuffers(EGLDisplay dpy, EGLSurface surface) {
    if (!imgui_ready) {
        IMGUI_CHECKVERSION();
        ImGui::CreateContext();
        ImGui::StyleColorsDark();
        ImGui_ImplOpenGL3_Init("#version 300 es");
        imgui_ready = true;
        LOGI("ImGui initialized on eglSwapBuffers");
    }

    refreshTelemetry();

    ImGui_ImplOpenGL3_NewFrame();
    ImGui::NewFrame();
    drawResearchHud();
    ImGui::Render();
    ImGui_ImplOpenGL3_RenderDrawData(ImGui::GetDrawData());

    return real_eglSwapBuffers(dpy, surface);
}

static void installEglHook() {
    void* egl = dlopen("libEGL.so", RTLD_NOW);
    if (!egl) {
        LOGI("dlopen libEGL failed");
        return;
    }
    void* sym = dlsym(egl, "eglSwapBuffers");
    if (!sym) {
        LOGI("eglSwapBuffers not found");
        return;
    }
    A64HookFunction(sym, (void*)hook_eglSwapBuffers, (void**)&real_eglSwapBuffers);
    LOGI("eglSwapBuffers hooked");
}

extern "C" JNIEXPORT jint JNI_OnLoad(JavaVM* vm, void* reserved) {
    (void)vm;
    (void)reserved;
    LOGI("ssm_research_hud loaded — educational read-only HUD");
    installEglHook();
    return JNI_VERSION_1_6;
}
