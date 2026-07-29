# دانلود مستقیم — Soccer Stars Assist v1.2.4

## APK امضا‌شده (خروجی ما)

**لینک مستقیم:**

https://github.com/hoseinzeto2-source/Tesf/raw/cursor/soccer-stars-lib-dump-b2df/releases/SoccerStarsAssist-v1.2.4.apk

**نسخه:** 1.2.4 (versionCode 15)  
**اندازه:** ~1.7 MB  
**Package:** `com.accessibility.soccerstars`

### نصب

```bash
# دانلود
curl -L -o SoccerStarsAssist-v1.2.4.apk \
  "https://github.com/hoseinzeto2-source/Tesf/raw/cursor/soccer-stars-lib-dump-b2df/releases/SoccerStarsAssist-v1.2.4.apk"

adb install -r SoccerStarsAssist-v1.2.4.apk
```

---

## ImGui کجاست؟ — overlay جدا، نه داخل so.apks

| روش | توضیح | کرش بازی؟ | آنتی‌چیت؟ |
|-----|--------|-----------|-----------|
| **Assist v1.2.4 (فعلی)** | ImGui-style HUD روی overlay جدا | خیر | کم‌ریسک (بازی دست‌نخورده) |
| **تزریق داخل so.apks** | patch کردن APK Miniclip | ممکن | **ریسک بن بالا** |

**قرار ما از اول:** ابزار کمکی overlay + تحلیل lib — **نه** mod رسمی `so.apks`.

تزریق ImGui native داخل `libgame-SSM` یا repack `so.apks`:
- امضای Miniclip از بین می‌رود
- آنلاین احتمال تشخیص / بن
- هر آپدیت بازی patch را می‌شکند

---

## گوشی روت — خواندن همه مشخصات از خود بازی

برای **داده واقعی از حافظه بازی** (نه فقط vision):

### پیش‌نیاز

```bash
pip install frida-tools
adb devices
# frida-server روی گوشی (arm64) با root
```

### اسکریپت telemetry کامل (root)

```bash
cd tools/root-hud
frida -U com.miniclip.soccerstars -l frida/root_live_telemetry.js
```

داخل match هر 2 ثانیه لاگ می‌زند:
- موقعیت توپ/مهره (X,Y نرمال)
- سرعت، trail/squash تخمینی
- exportهای فیزیک (`sInternalVelocity`, debug flags)
- selectorهای شلیک ثبت‌شده

```javascript
rpc.snapshot()   // یک‌بار
rpc.enablePhysicsDebug()
```

### فقط خواندن (read-only)

- Hookها فقط **می‌خوانند** حافظه — شلیک تزریق نمی‌شود
- برای bug bounty / تحلیل شخصی
- آنلاین: همچنان سرور `shot_outcome` معتبر است

---

## PR

https://github.com/hoseinzeto2-source/Tesf/pull/3
