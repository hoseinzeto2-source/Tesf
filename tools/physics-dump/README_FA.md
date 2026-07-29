# استخراج فیزیک Soccer Stars با گوشی روت

این راهنما برای **بهبود دقت ابزار کمکی** است، نه هک بازی.

## چه چیزی از APK می‌دانیم؟

موتور بازی (`libgame-SSM-...so`) این‌ها را دارد:

| نماد export | کاربرد |
|-------------|--------|
| `sPhysicsDebugEnabled` | روشن کردن دیباگ فیزیک |
| `sPhysicsDebugDiagnostics` | اطلاعات تشخیصی فیزیک |
| `sInternalVelocity` | سرعت داخلی |
| `takeActualShot:angle:spin:playSound:` | شلیک واقعی (داخل باینری، strip شده) |
| `mFrictionFactor` | اصطکاک |
| `mEdgeRestitutionFactor` | بازتاب دیوار |
| `dragForce` / `maxPower` | قدرت کشیدن |

شلیک شبکه: `shot_taken { angle, power, puck_id }`

---

## روش ۱ — سریع: Frida + Physics Debug (پیشنهادی)

### پیش‌نیاز روی PC
```bash
pip install frida-tools
adb devices
```

### روی گوشی (روت)
- Soccer Stars نصب باشد
- `frida-server` متناسب با معماری گوشی (arm64) اجرا شود

### اجرا
```bash
cd tools/physics-dump

# بازی را باز کنید، بعد:
frida -U com.miniclip.soccerstars -l frida/enable_physics_debug.js
```

اگر debug فیزیک فعال شود، ممکن است روی زمین **بردار/خط دیباگ** ببینید — از آن اسکرین‌شات بگیرید.

---

## روش ۲ — Dump کتابخانه native

### الف) از فایل `.apks` (بدون گوشی — پیشنهادی)

فایل `so.apks` از لینک رسمی نسخه 36.14.4:

```bash
cd tools/physics-dump
chmod +x scripts/*.sh

# دانلود خودکار از dl.mr-cheat.ir/so.apks
./scripts/extract_lib_from_apks.sh

# یا با فایل محلی:
./scripts/extract_lib_from_apks.sh /path/to/so.apks ./dumped

# نماد‌ها و رشته‌های مهم:
./scripts/dump_symbols.sh ./dumped/libgame-SSM.so ./dumped/symbols.txt
```

خروجی:
- `dumped/libgame-SSM-GooglePlay-Gold-Release-Module-1013.so` (~41 MB)
- `dumped/libgame-SSM.so` (symlink برای Ghidra/Frida)

### ب) از گوشی روت (adb)

```bash
chmod +x scripts/pull_lib.sh
./scripts/pull_lib.sh ./dumped
```

بعد با Ghidra:
1. فایل `dumped/libgame-SSM.so` را باز کنید
2. Search → For Strings → `takeActualShot:angle:spin:playSound:`
3. روی string راست‌کلیک → References
4. تابع والد = منطق شلیک
5. در همان کلاس دنبال `mFrictionFactor` و `mEdgeRestitutionFactor` بگردید

آدرس تابع را در Frida:
```javascript
const base = Process.findModuleByName("libgame-SSM-GooglePlay-Gold-Release-Module-1013.so").base;
Interceptor.attach(base.add(0xOFFSET_FROM_GHIDRA), {
  onEnter(args) { console.log("shot", args[0], args[1]); }
});
```

---

## روش ۳ — کالیبره عملی (بدون RE عمیق)

1. ابزار Assist را نصب کنید
2. چند شوت ثابت بزنید (کم‌قدرت، متوسط، زیاد)
3. مسیر پیش‌بینی‌شده vs مسیر واقعی توپ را یادداشت کنید
4. در `AssistConfig` این‌ها را تنظیم کنید:
   - `maxShotSpeed`
   - `maxShotPower`
   - `friction`
   - `restitution`

یا خروجی Frida را تبدیل کنید:
```bash
python3 scripts/parse_calibration.py frida.log -o physics.json
adb push physics.json /sdcard/SoccerStarsAssist/physics.json
```

---

## روش ۴ — اسکن حافظه زنده

```bash
frida -U com.miniclip.soccerstars -l frida/scan_live_state.js
```

داخل match و هنگام کشیدن مهره:
```javascript
rpc.dumpState()
```

خروجی مختصات نرمال‌شده (0..1) مهره/توپ را می‌دهد → برای کالیبره scale صفحه.

---

## فایل فیزیک روی گوشی

مسیر پیشنهادی:
```
/sdcard/SoccerStarsAssist/physics.json
```

نمونه:
```json
{
  "maxShotPower": 140.0,
  "maxShotSpeed": 31.5,
  "friction": 0.982,
  "restitution": 0.93,
  "powerScale": 1.08
}
```

---

## هشدار

- فقط برای **ابزار دسترسی‌پذیری شخصی**
- آنلاین: فقط خواندن/overlay — نه تزریق شلیک
- نسخه بازی عوض شود → ممکن است offsetها تغییر کنند
