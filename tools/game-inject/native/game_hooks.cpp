#include "game_hooks.h"

#include "telemetry.h"
#include "memory_safe.h"
#include "third_party/And64InlineHook.hpp"

#include <android/log.h>
#include <dlfcn.h>
#include <pthread.h>
#include <chrono>
#include <thread>
#include <cstring>
#include <mutex>
#include <unordered_set>

#define LOG_TAG "SSMResearchHUD"
#define LOGI(...) __android_log_print(ANDROID_LOG_INFO, LOG_TAG, __VA_ARGS__)
#define LOGE(...) __android_log_print(ANDROID_LOG_ERROR, LOG_TAG, __VA_ARGS__)

static const char* kGameLib = "libgame-SSM-GooglePlay-Gold-Release-Module-1013.so";

using MethodGetNameFn = void* (*)(void* method);
using SelGetNameFn = const char* (*)(void* sel);
using MethodSetImplementationFn = void* (*)(void* method, void* imp);
using MethodGetImplementationFn = void* (*)(void* method);

static MethodSetImplementationFn real_method_setImplementation = nullptr;
static MethodGetImplementationFn real_method_getImplementation = nullptr;
static MethodGetNameFn method_getName_fn = nullptr;
static SelGetNameFn sel_getName_fn = nullptr;

static bool g_hooks_installed = false;
static bool g_primary_hooked = false;
static std::mutex g_imp_mutex;
static std::unordered_set<void*> g_patched_imps;

static void (*real_shot_outcome_imp)(void*, void*, void*) = nullptr;

static bool isShotOutcomeArgSelector(const char* name) {
    if (!name) return false;
    return strcmp(name, "setShotOutcome:") == 0 || strcmp(name, "shotOutcomeUpdateProcess:") == 0;
}

static void onHookedArg(void* arg) {
    if (!arg) return;
    MatchSnapshot snap;
    if (parseShotOutcomeObject(arg, snap)) {
        commitHookSnapshot(snap);
        LOGI("hook: shot_outcome score=%.0f:%.0f ball=%d", snap.score_home, snap.score_away,
             snap.ball_valid ? 1 : 0);
    }
}

static void hook_shot_outcome_imp(void* self, void* sel, void* arg) {
    onHookedArg(arg);
    if (real_shot_outcome_imp) real_shot_outcome_imp(self, sel, arg);
}

static void tryPatchImp(void* imp, const char* sel_name) {
    if (!imp || g_primary_hooked) return;
    std::lock_guard<std::mutex> lock(g_imp_mutex);
    if (g_patched_imps.count(imp)) return;

    void* trampoline = nullptr;
    A64HookFunction(imp, (void*)hook_shot_outcome_imp, &trampoline);
    real_shot_outcome_imp = (void (*)(void*, void*, void*))trampoline;
    g_patched_imps.insert(imp);
    g_primary_hooked = true;
    LOGI("hook: patched %s IMP %p", sel_name ? sel_name : "?", imp);
}

static void maybeHookMethod(void* method) {
    if (!method || !method_getName_fn || !sel_getName_fn || !real_method_getImplementation) return;

    void* sel = method_getName_fn(method);
    const char* name = sel_getName_fn(sel);
    if (!name || !isShotOutcomeArgSelector(name)) return;

    void* imp = real_method_getImplementation(method);
    tryPatchImp(imp, name);
}

static void* hook_method_setImplementation(void* method, void* imp) {
    void* result = real_method_setImplementation(method, imp);
    maybeHookMethod(method);
    return result;
}

static void* hook_method_getImplementation(void* method) {
    void* imp = real_method_getImplementation(method);
    maybeHookMethod(method);
    return imp;
}

static bool installLibgameHooks() {
    void* lib = dlopen(kGameLib, RTLD_NOLOAD);
    if (!lib) return false;

    if (real_method_setImplementation) return true;

    auto set_sym = (void*)dlsym(lib, "method_setImplementation");
    auto get_sym = (void*)dlsym(lib, "method_getImplementation");
    method_getName_fn = (MethodGetNameFn)dlsym(lib, "method_getName");
    sel_getName_fn = (SelGetNameFn)dlsym(lib, "sel_getName");
    if (!set_sym || !get_sym || !method_getName_fn || !sel_getName_fn) {
        LOGE("hook: missing objc exports");
        return false;
    }

    A64HookFunction(set_sym, (void*)hook_method_setImplementation, (void**)&real_method_setImplementation);
    A64HookFunction(get_sym, (void*)hook_method_getImplementation, (void**)&real_method_getImplementation);
    LOGI("hook: objc method hooks installed");
    return true;
}

static void* hookPollThread(void*) {
    for (int i = 0; i < 240; i++) {
        if (isGameLibLoaded()) {
            refreshReadableMaps();
            if (installLibgameHooks()) {
                g_hooks_installed = true;
                break;
            }
        }
        std::this_thread::sleep_for(std::chrono::milliseconds(250));
    }
    return nullptr;
}

void installGameHooks() {
    if (g_hooks_installed) return;
    pthread_t t;
    pthread_create(&t, nullptr, hookPollThread, nullptr);
    pthread_detach(t);
}

void pollGameHooks() {
    if (!g_hooks_installed && isGameLibLoaded()) {
        refreshReadableMaps();
        if (installLibgameHooks()) g_hooks_installed = true;
    }
}

bool gameHooksInstalled() {
    return g_hooks_installed;
}
