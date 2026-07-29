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
#include <vector>
#include <string>

#define LOG_TAG "SSMResearchHUD"
#define LOGI(...) __android_log_print(ANDROID_LOG_INFO, LOG_TAG, __VA_ARGS__)
#define LOGE(...) __android_log_print(ANDROID_LOG_ERROR, LOG_TAG, __VA_ARGS__)

static const char* kGameLib = "libgame-SSM-GooglePlay-Gold-Release-Module-1013.so";
static constexpr int kMaxImpHooks = 16;

using MethodGetNameFn = void* (*)(void* method);
using SelGetNameFn = const char* (*)(void* sel);
using MethodSetImplementationFn = void* (*)(void* method, void* imp);
using MethodGetImplementationFn = void* (*)(void* method);
using SelRegisterNameFn = void* (*)(const char* name);
using ObjcImp3 = void (*)(void*, void*, void*);

static MethodSetImplementationFn real_method_setImplementation = nullptr;
static MethodGetImplementationFn real_method_getImplementation = nullptr;
static MethodGetNameFn method_getName_fn = nullptr;
static SelGetNameFn sel_getName_fn = nullptr;
static SelRegisterNameFn real_sel_registerName = nullptr;

static bool g_hooks_installed = false;
static std::mutex g_imp_mutex;
static std::mutex g_queue_mutex;
static std::unordered_set<void*> g_patched_imps;
static std::vector<std::pair<void*, std::string>> g_pending_patches;

struct ImpSlot {
    ObjcImp3 real = nullptr;
};
static ImpSlot g_slots[kMaxImpHooks];
static int g_slot_count = 0;

static bool selectorHasArg(const char* name) {
    return name && strchr(name, ':') != nullptr;
}

static bool isWatchSelector(const char* name) {
    if (!name || !selectorHasArg(name)) return false;

    static const char* kExact[] = {
        "setShotOutcome:",
        "shotOutcomeUpdateProcess:",
        "networkEventShotOutcome:",
        "networkEventGameStarted:",
        "networkEventAnimateShot:",
        nullptr,
    };
    for (int i = 0; kExact[i]; i++) {
        if (strcmp(name, kExact[i]) == 0) return true;
    }
    return false;
}

static bool isNetworkShotOutcome(const char* name) {
    return name && strcmp(name, "networkEventShotOutcome:") == 0;
}

static bool isNetworkGameStarted(const char* name) {
    return name && strcmp(name, "networkEventGameStarted:") == 0;
}

static bool isAnimateShot(const char* name) {
    return name && strcmp(name, "networkEventAnimateShot:") == 0;
}

static bool isValidArg(const void* p) {
    uintptr_t v = reinterpret_cast<uintptr_t>(p);
    return v > 0x10000 && v < 0x7fffffffffffULL;
}

static void dispatchHook(void* sel, void* arg) {
    if (!sel_getName_fn) return;
    const char* name = sel_getName_fn(sel);

    if (!arg || !isValidArg(arg)) return;

    MatchSnapshot snap;
    bool ok = false;

    if (isNetworkShotOutcome(name) || isNetworkGameStarted(name) || isAnimateShot(name)) {
        ok = parseNetworkRequest(arg, snap);
    } else if (strcmp(name, "setShotOutcome:") == 0 || strcmp(name, "shotOutcomeUpdateProcess:") == 0) {
        ok = parseShotOutcomeObject(arg, snap);
        if (!ok) ok = parseNetworkRequest(arg, snap);
    } else {
        ok = parseShotOutcomeObject(arg, snap);
        if (!ok) ok = parseShotTakenObject(arg, snap);
        if (!ok) ok = parseNetworkRequest(arg, snap);
    }

    if (ok) {
        commitHookSnapshot(snap, name);
        LOGI("hook [%s] score=%.0f:%.0f ball=%d bodies=%d",
             name ? name : "?",
             snap.score_home, snap.score_away,
             snap.ball_valid ? 1 : 0, snap.body_count);
    }
}

#define HOOK_SLOT_BODY(I) \
    dispatchHook(sel, arg); \
    if (g_slots[I].real) g_slots[I].real(self, sel, arg);

static void hook_slot_0(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(0) }
static void hook_slot_1(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(1) }
static void hook_slot_2(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(2) }
static void hook_slot_3(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(3) }
static void hook_slot_4(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(4) }
static void hook_slot_5(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(5) }
static void hook_slot_6(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(6) }
static void hook_slot_7(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(7) }
static void hook_slot_8(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(8) }
static void hook_slot_9(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(9) }
static void hook_slot_10(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(10) }
static void hook_slot_11(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(11) }
static void hook_slot_12(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(12) }
static void hook_slot_13(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(13) }
static void hook_slot_14(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(14) }
static void hook_slot_15(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(15) }

static void* hook_slot_ptrs[kMaxImpHooks] = {
    (void*)hook_slot_0, (void*)hook_slot_1, (void*)hook_slot_2, (void*)hook_slot_3,
    (void*)hook_slot_4, (void*)hook_slot_5, (void*)hook_slot_6, (void*)hook_slot_7,
    (void*)hook_slot_8, (void*)hook_slot_9, (void*)hook_slot_10, (void*)hook_slot_11,
    (void*)hook_slot_12, (void*)hook_slot_13, (void*)hook_slot_14, (void*)hook_slot_15,
};

static bool isImpInOurLib(void* imp) {
    Dl_info info{};
    if (!imp || dladdr(imp, &info) == 0 || !info.dli_fname) return false;
    return strstr(info.dli_fname, "ssm_research_hud") != nullptr;
}

static void tryPatchImp(void* imp, const char* sel_name) {
    if (!imp || isImpInOurLib(imp)) return;

    std::lock_guard<std::mutex> lock(g_imp_mutex);
    if (g_patched_imps.count(imp)) return;
    if (g_slot_count >= kMaxImpHooks) {
        LOGE("hook: max IMP slots reached");
        return;
    }

    const int slot = g_slot_count++;
    void* trampoline = nullptr;
    A64HookFunction(imp, hook_slot_ptrs[slot], &trampoline);
    g_slots[slot].real = (ObjcImp3)trampoline;
    g_patched_imps.insert(imp);
    telemetrySetHookPatchedCount(g_slot_count);
    LOGI("hook: slot %d patched %s @ %p", slot, sel_name ? sel_name : "?", imp);
}

static void queuePatchImp(void* imp, const char* sel_name) {
    if (!imp || isImpInOurLib(imp)) return;
    std::lock_guard<std::mutex> lock(g_imp_mutex);
    if (g_patched_imps.count(imp)) return;

    std::lock_guard<std::mutex> qlock(g_queue_mutex);
    g_pending_patches.emplace_back(imp, sel_name ? sel_name : "");
}

static void drainPendingPatches() {
    std::vector<std::pair<void*, std::string>> batch;
    {
        std::lock_guard<std::mutex> lock(g_queue_mutex);
        if (g_pending_patches.empty()) return;
        batch.swap(g_pending_patches);
    }
    for (const auto& entry : batch) {
        tryPatchImp(entry.first, entry.second.c_str());
    }
}

static void maybeHookMethod(void* method) {
    if (!method || !method_getName_fn || !sel_getName_fn || !real_method_getImplementation) return;

    void* sel = method_getName_fn(method);
    const char* name = sel_getName_fn(sel);
    if (!isWatchSelector(name)) return;

    void* imp = real_method_getImplementation(method);
    queuePatchImp(imp, name);
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

static void* hook_sel_registerName(const char* name) {
    void* sel = real_sel_registerName(name);
    if (isWatchSelector(name)) {
        LOGI("sel_register: %s", name);
    }
    return sel;
}

static bool installLibgameHooks() {
    void* lib = dlopen(kGameLib, RTLD_NOLOAD);
    if (!lib) return false;

    if (real_method_setImplementation) return true;

    auto set_sym = (void*)dlsym(lib, "method_setImplementation");
    auto get_sym = (void*)dlsym(lib, "method_getImplementation");
    auto sel_reg = (void*)dlsym(lib, "sel_registerName");
    method_getName_fn = (MethodGetNameFn)dlsym(lib, "method_getName");
    sel_getName_fn = (SelGetNameFn)dlsym(lib, "sel_getName");
    if (!set_sym || !get_sym || !method_getName_fn || !sel_getName_fn) {
        LOGE("hook: missing objc exports");
        return false;
    }

    A64HookFunction(set_sym, (void*)hook_method_setImplementation, (void**)&real_method_setImplementation);
    A64HookFunction(get_sym, (void*)hook_method_getImplementation, (void**)&real_method_getImplementation);
    if (sel_reg) {
        A64HookFunction(sel_reg, (void*)hook_sel_registerName, (void**)&real_sel_registerName);
    }
    LOGI("hook: objc runtime intercept active");
    return true;
}

static void* hookPollThread(void*) {
    for (int i = 0; i < 300; i++) {
        if (isGameLibLoaded()) {
            refreshReadableMaps();
            if (installLibgameHooks()) {
                g_hooks_installed = true;
                LOGI("hook: ready (deferred IMP patch on EGL thread)");
                break;
            }
        }
        std::this_thread::sleep_for(std::chrono::milliseconds(200));
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
    drainPendingPatches();
}

bool gameHooksInstalled() {
    return g_hooks_installed;
}
