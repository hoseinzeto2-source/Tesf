# تزریق Dear ImGui داخل بازی — آموزش / Bug Bounty

**فقط برای تحقیق آموزشی دانشگاه — read-only (بدون تزریق شلیک)**

## چه کار می‌کند؟

1. `libssm_research_hud.so` — Dear ImGui v1.90 + hook `eglSwapBuffers`
2. در `attachBaseContext` بازی `loadLibrary("ssm_research_hud")` فراخوانی می‌شود
3. پنل ImGui داخل پروسه بازی نشان می‌دهد:
   - موقعیت مهره/توپ (X,Y نرمال)
   - سرعت (vx, vy)
   - `sInternalVelocity`, `sPhysicsDebugEnabled`

## پیش‌نیاز

```bash
export ANDROID_HOME=/path/to/android-sdk
# baksmali + smali (apt install smali), java keytool, cmake, Android NDK
# Dex-only patch — no apktool resource rebuild (invalid $applovin drawable names)
```

## ساخت و patch (روی سیستم خودتان)

```bash
# 1) فایل رسمی
curl -L -o downloads/so.apks https://dl.mr-cheat.ir/so.apks

# 2) patch
chmod +x tools/game-inject/patch_apks.sh
./tools/game-inject/patch_apks.sh downloads/so.apks build/patched-output
```

خروجی: `build/patched-output/soccer-stars-research-hud.apks`

## نصب

```bash
adb install-multiple build/patched-output/work/base-patched.apk \
  build/patched-output/work/split-patched.apk
```

یا **SAI** (Split APKs Installer) با فایل `.apks`

## محدودیت‌ها (صادق)

| مورد | توضیح |
|------|--------|
| امتیاز آنلاین | از سرور `shot_outcome` — HUD فقط نمایش |
| آنتی‌چیت | امضای Miniclip عوض شده — آنلاین ممکن است خطا بدهد |
| کرش | ممکن است روی بعضی ROMها — برای آزمایش offline/local |
| Z | فیزیک 2D — Z ندارد |

## چرا لینک عمومی نمی‌دهیم؟

APK پچ‌شده بازی تجاری Miniclip است. اسکریپت را **محلی** اجرا کنید — خروجی روی دستگاه شما.

## ImGui منبع

https://github.com/ocornut/imgui

Hook: And64InlineHook (Rprop)
