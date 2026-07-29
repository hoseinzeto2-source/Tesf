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

#define LOG_TAG "SSMResearchHUD"
#define LOGI(...) __android_log_print(ANDROID_LOG_INFO, LOG_TAG, __VA_ARGS__)
#define LOGE(...) __android_log_print(ANDROID_LOG_ERROR, LOG_TAG, __VA_ARGS__)

static const char* kGameLib = "libgame-SSM-GooglePlay-Gold-Release-Module-1013.so";
static constexpr int kMaxImpHooks = 24;

// Cocotron objc offsets (build 1013, from RE)
static constexpr uintptr_t kOffLookupClass = 0x1d7bfdc;
static constexpr uintptr_t kOffClassNameEntry = 0x1d7bef0;
static constexpr uintptr_t kOffObjcExecClass = 0x1d7d3e4;
static constexpr uintptr_t kOffGlobalClassTable = 0x2994098;

using MethodGetNameFn = void* (*)(void* method);
using SelGetNameFn = const char* (*)(void* sel);
using MethodSetImplementationFn = void* (*)(void* method, void* imp);
using MethodGetImplementationFn = void* (*)(void* method);
using SelRegisterNameFn = void* (*)(const char* name);
using LookupClassFn = void* (*)(const char* name);
using ClassNameEntryFn = void* (*)(void* table, uintptr_t index);
using ObjcExecClassFn = void (*)(void* cls);
using ObjcImp3 = void (*)(void*, void*, void*);

static MethodSetImplementationFn real_method_setImplementation = nullptr;
static MethodGetImplementationFn real_method_getImplementation = nullptr;
static MethodGetNameFn method_getName_fn = nullptr;
static SelGetNameFn sel_getName_fn = nullptr;
static SelRegisterNameFn real_sel_registerName = nullptr;
static LookupClassFn lookup_class_fn = nullptr;
static ClassNameEntryFn class_name_entry_fn = nullptr;
static ObjcExecClassFn real_objc_execClass = nullptr;

static bool g_hooks_installed = false;
static std::mutex g_wrap_mutex;
static std::unordered_set<void*> g_wrapped_source_imps;
static std::unordered_set<void*> g_wrapped_methods;
static thread_local bool g_in_hook_dispatch = false;
static int g_classes_scanned = 0;
static int g_poll_ticks = 0;

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

static uintptr_t gameLibBase() {
    void* lib = dlopen(kGameLib, RTLD_NOLOAD);
    if (!lib) return 0;
    void* sym = (void*)dlsym(lib, "method_setImplementation");
    if (!sym) return 0;
    Dl_info info{};
    if (!dladdr(sym, &info) || !info.dli_fbase) return 0;
    return (uintptr_t)info.dli_fbase;
}

static void dispatchHook(void* sel, void* arg) {
    if (!sel_getName_fn || g_in_hook_dispatch) return;
    const char* name = sel_getName_fn(sel);
    if (!arg || !isValidArg(arg)) return;

    g_in_hook_dispatch = true;

    MatchSnapshot snap;
    bool ok = false;

    if (isNetworkGameStarted(name)) {
        ok = parseGameStartedObject(arg, snap) || parseNetworkRequest(arg, snap);
    } else if (isNetworkShotOutcome(name) || isAnimateShot(name)) {
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
        LOGI("telemetry [%s] score=%.0f:%.0f ball=%d bodies=%d",
             name ? name : "?",
             snap.score_home, snap.score_away,
             snap.ball_valid ? 1 : 0, snap.body_count);
    }

    g_in_hook_dispatch = false;
}

#define HOOK_SLOT_BODY(I) \
    if (g_slots[I].real) g_slots[I].real(self, sel, arg); \
    dispatchHook(sel, arg);

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
static void hook_slot_16(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(16) }
static void hook_slot_17(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(17) }
static void hook_slot_18(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(18) }
static void hook_slot_19(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(19) }
static void hook_slot_20(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(20) }
static void hook_slot_21(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(21) }
static void hook_slot_22(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(22) }
static void hook_slot_23(void* self, void* sel, void* arg) { HOOK_SLOT_BODY(23) }

static void* hook_slot_ptrs[kMaxImpHooks] = {
    (void*)hook_slot_0, (void*)hook_slot_1, (void*)hook_slot_2, (void*)hook_slot_3,
    (void*)hook_slot_4, (void*)hook_slot_5, (void*)hook_slot_6, (void*)hook_slot_7,
    (void*)hook_slot_8, (void*)hook_slot_9, (void*)hook_slot_10, (void*)hook_slot_11,
    (void*)hook_slot_12, (void*)hook_slot_13, (void*)hook_slot_14, (void*)hook_slot_15,
    (void*)hook_slot_16, (void*)hook_slot_17, (void*)hook_slot_18, (void*)hook_slot_19,
    (void*)hook_slot_20, (void*)hook_slot_21, (void*)hook_slot_22, (void*)hook_slot_23,
};

static bool isImpInOurLib(void* imp) {
    Dl_info info{};
    if (!imp || dladdr(imp, &info) == 0 || !info.dli_fname) return false;
    return strstr(info.dli_fname, "ssm_research_hud") != nullptr;
}

static bool isOurHookImp(void* imp) {
    if (!imp) return false;
    for (int i = 0; i < kMaxImpHooks; i++) {
        if (hook_slot_ptrs[i] == imp) return true;
    }
    return false;
}

static void* wrapImpIfNeeded(void* imp, const char* sel_name) {
    if (!imp || isImpInOurLib(imp) || isOurHookImp(imp)) return imp;

    std::lock_guard<std::mutex> lock(g_wrap_mutex);
    if (g_wrapped_source_imps.count(imp)) {
        for (int i = 0; i < g_slot_count; i++) {
            if ((void*)g_slots[i].real == imp) return hook_slot_ptrs[i];
        }
        return imp;
    }
    if (g_slot_count >= kMaxImpHooks) {
        LOGE("hook: max wrap slots reached");
        return imp;
    }

    const int slot = g_slot_count++;
    g_slots[slot].real = (ObjcImp3)imp;
    g_wrapped_source_imps.insert(imp);
    telemetrySetHookPatchedCount(g_slot_count);
    LOGI("hook: wrap slot %d %s", slot, sel_name ? sel_name : "?");
    return hook_slot_ptrs[slot];
}

static void wrapSingleMethod(void* method) {
    if (!method || !method_getName_fn || !sel_getName_fn || !real_method_getImplementation ||
        !real_method_setImplementation) {
        return;
    }

    void* sel = method_getName_fn(method);
    const char* name = sel_getName_fn(sel);
    if (!isWatchSelector(name)) return;

    std::lock_guard<std::mutex> lock(g_wrap_mutex);
    if (g_wrapped_methods.count(method)) return;

    void* imp = real_method_getImplementation(method);
    if (!imp || isOurHookImp(imp)) {
        g_wrapped_methods.insert(method);
        return;
    }

    void* wrapped = wrapImpIfNeeded(imp, name);
    if (wrapped != imp) {
        real_method_setImplementation(method, wrapped);
        LOGI("hook: wrapped %s", name);
    }
    g_wrapped_methods.insert(method);
}

static void wrapMethodList(const uint8_t* list, int count) {
    if (!list || count <= 0) return;
    for (int i = 0; i < count && i < 800; i++) {
        void* method = nullptr;
        if (!safeRead(list + i * 8, &method, sizeof(void*))) continue;
        if (!method || !isValidArg(method)) continue;
        wrapSingleMethod(method);
    }
}

static void wrapWatchMethodsInClass(void* cls) {
    if (!cls || !isReadable(cls, 32)) return;

    void* class_d = nullptr;
    if (!safeRead(reinterpret_cast<uint8_t*>(cls) + 0x18, &class_d, sizeof(void*))) return;
    if (!class_d || !isReadable(class_d, 64)) return;

    uint16_t inst_count = 0;
    uint16_t meta_count = 0;
    if (!safeRead(reinterpret_cast<uint8_t*>(class_d) + 0x10, &inst_count, sizeof(uint16_t))) return;
    if (!safeRead(reinterpret_cast<uint8_t*>(class_d) + 0x12, &meta_count, sizeof(uint16_t))) return;

    const auto* inst_list = reinterpret_cast<const uint8_t*>(class_d) + 0x18;
    wrapMethodList(inst_list, inst_count);
    wrapMethodList(inst_list + inst_count * 8, meta_count);
}

static void hook_objc_execClass(void* cls) {
    if (real_objc_execClass) real_objc_execClass(cls);
    wrapWatchMethodsInClass(cls);
}

static bool readCString(const void* ptr, char* out, size_t cap) {
    if (!ptr || !out || cap == 0) return false;
    for (size_t i = 0; i < cap - 1; i++) {
        char c = 0;
        if (!safeRead(reinterpret_cast<const uint8_t*>(ptr) + i, &c, 1)) return false;
        out[i] = c;
        if (c == '\0') return i > 0;
    }
    out[cap - 1] = '\0';
    return false;
}

static void scanAllRegisteredClasses() {
    if (!lookup_class_fn || !class_name_entry_fn) return;

    uintptr_t base = gameLibBase();
    if (!base) return;

    void* table_meta = nullptr;
    void** global = reinterpret_cast<void**>(base + kOffGlobalClassTable);
    if (!safeRead(global, &table_meta, sizeof(void*)) || !table_meta) {
        LOGE("scan: class table missing");
        return;
    }

    int32_t count = 0;
    if (!safeRead(reinterpret_cast<uint8_t*>(table_meta) + 0x10, &count, sizeof(int32_t))) return;
    count &= 0x7fffffff;
    if (count <= 0 || count > 20000) {
        LOGE("scan: bad class count %d", count);
        return;
    }

    int wrapped_before = g_slot_count;
    int classes = 0;

    for (int i = 0; i < count; i++) {
        void* name_entry = class_name_entry_fn(table_meta, static_cast<uintptr_t>(i));
        if (!name_entry) continue;

        void* name_ptr = nullptr;
        if (!safeRead(name_entry, &name_ptr, sizeof(void*)) || !name_ptr) continue;

        char name[96] = {};
        if (!readCString(name_ptr, name, sizeof(name))) continue;

        void* cls = lookup_class_fn(name);
        if (!cls) continue;

        classes++;
        wrapWatchMethodsInClass(cls);
    }

    g_classes_scanned = classes;
    LOGI("scan: %d classes, hooks %d -> %d", classes, wrapped_before, g_slot_count);

    static const char* kPriority[] = {
        "MenuManager", "GameplayManager", "MainManager", "StateManager", nullptr,
    };
    for (int i = 0; kPriority[i]; i++) {
        void* cls = lookup_class_fn(kPriority[i]);
        if (cls) wrapWatchMethodsInClass(cls);
    }
}

static void* hook_method_setImplementation(void* method, void* imp) {
    if (method && method_getName_fn && sel_getName_fn) {
        void* sel = method_getName_fn(method);
        const char* name = sel_getName_fn(sel);
        if (isWatchSelector(name)) {
            imp = wrapImpIfNeeded(imp, name);
            std::lock_guard<std::mutex> lock(g_wrap_mutex);
            g_wrapped_methods.insert(method);
        }
    }
    return real_method_setImplementation(method, imp);
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

    uintptr_t base = gameLibBase();
    lookup_class_fn = (LookupClassFn)(base + kOffLookupClass);
    class_name_entry_fn = (ClassNameEntryFn)(base + kOffClassNameEntry);

    A64HookFunction(set_sym, (void*)hook_method_setImplementation, (void**)&real_method_setImplementation);
    real_method_getImplementation = (MethodGetImplementationFn)get_sym;

    void* exec_sym = (void*)(base + kOffObjcExecClass);
    A64HookFunction(exec_sym, (void*)hook_objc_execClass, (void**)&real_objc_execClass);

    if (sel_reg) {
        A64HookFunction(sel_reg, (void*)hook_sel_registerName, (void**)&real_sel_registerName);
    }

    LOGI("hook: cocotron class scan + execClass (base=%p)", (void*)base);
    scanAllRegisteredClasses();
    return true;
}

static void* hookPollThread(void*) {
    for (int i = 0; i < 300; i++) {
        if (isGameLibLoaded()) {
            refreshReadableMaps();
            if (installLibgameHooks()) {
                g_hooks_installed = true;
                LOGI("hook: ready, classes=%d hooks=%d", g_classes_scanned, g_slot_count);
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
    g_poll_ticks++;
    if (!g_hooks_installed && isGameLibLoaded()) {
        refreshReadableMaps();
        if (installLibgameHooks()) g_hooks_installed = true;
    }
    if (g_hooks_installed && g_slot_count == 0 && (g_poll_ticks % 180 == 0)) {
        scanAllRegisteredClasses();
    }
}

bool gameHooksInstalled() {
    return g_hooks_installed;
}

int gameClassesScanned() {
    return g_classes_scanned;
}
