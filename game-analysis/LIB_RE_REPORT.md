# Soccer Stars — تحلیل حرفه‌ای libgame-SSM (RE Report)

**نسخه:** 36.14.4 (build 1013)  
**فایل:** `libgame-SSM-GooglePlay-Gold-Release-Module-1013.so`  
**معماری:** ELF64 AArch64 (~41 MB)  
**ابزارها:** radare2, readelf, nm, strings, Python, Frida

---

## ۱. «قفل» چیست؟

این lib **رمزنگاری سخت‌افزاری یا obfuscation شدید ندارد**. آنچه «قفل» به نظر می‌رسد:

| لایه | توضیح |
|------|--------|
| Native C++ + GNU ObjC | منطق داخل باینری؛ export عمومی کم |
| Server-authoritative | نتیجه آنلاین از سرور (`shot_outcome`) |
| Strip شده | `takeActualShot` export نیست — فقط selector string |
| iOS port | runtime ObjC embedded، نه JNI استاندارد Android |

**باز کردن = استاتیک (Ghidra/r2) + زنده (Frida) + assets (plist)**

---

## ۲. معماری موتور

```
Miniclip Jenkins: SSM-GooglePlay-Gold-Release-Module
        └── SSM/pool/          ← billiards-style physics engine
              ├── Framework/
              ├── Data/
              ├── GoldenShot/
              └── Gifting/...
```

- موتور: **SSM/pool** (شبیه pool/billiards)
- UI: Cocos2d-style (`.ccbi`, `.plist`)
- شبکه: **Protobuf** (`soccer.proto` embedded)
- ObjC: **GNU libobjc** (`sel_registerName`, `method_getImplementation`)

---

## ۳. Pipeline شلیک (از strings + type encodings)

```
[بازیکن مهره را می‌کشد]
    aimTo:angle:power:
         ↓
    calculateShot:power:angle:spin:
         ↓
    calculateFinalPower:forBall:
         ↓
    simulateAndSendShot:power:angle:      ← پیش‌نمایش / آماده‌سازی شبکه
         ↓
    takeActualShot:angle:spin:playSound:  ← اعمال impulse در world فیزیک
         ↓
    getBallBallCollision / getBallLineCollision
         ↓
    stopAllPhysics                       ← پایان نوبت
         ↓
    shot_taken → سرور { angle, power, puck_id, field_state[] }
         ↓
    shot_outcome ← سرور { puck_state[], امتیاز, نوبت بعد }
```

### آدرس virtual string (radare2 — برای Ghidra/Frida offset)

| Selector | vaddr (در فایل ELF) |
|----------|---------------------|
| `takeActualShot:angle:spin:playSound:` | `0x00fea754` |
| `calculateShot:power:angle:spin:` | `0x01157944` |
| `simulateAndSendShot:power:angle:` | `0x010dd115` |
| `stopAllPhysics` | `0x0101eb36` |
| `mFrictionFactor` | `0x01029719` |

**Frida:** `base = Module.findModuleByName(LIB).base` → offset = vaddr - image_base (معمولاً 0 برای این SO)

---

## ۴. Protobuf — ساختار داده مهره/توپ

### `puck_state`

```
position (object)
owner_enum
state_enum
puck_id
```

### `shot_taken`

```
player_id (string)
angle_ (double)
power_ (double)
puck_id (int)
shot_id (int)
extra_max_power_level (int)
field_state[] (repeated puck_state)
```

### پیام‌های match

| Message | نقش |
|---------|-----|
| `game_started` | لیست `puck_state` + بازیکن شروع |
| `aim_event` | حین کشیدن |
| `shot_taken` | شلیک نهایی |
| `animation_outcome` | نتیجه انیمیشن |
| `shot_outcome` | گل، امتیاز، `puck_state` جدید |

---

## ۵. فیزیک — کلاس‌ها و نماد‌ها

### کلاس‌های BallSettings (در lib)

- `GameSoccerBallSettings` — حالت عادی
- `IceGameSoccerBallSettings` — یخ
- `FireGameSoccerBallSettings` — آتش
- `BaseSoccerBallSettings`

### متغیرهای member (نه export)

- `mFrictionFactor` — اصطکاک
- `mEdgeRestitutionFactor` — بازتاب دیوار
- `mFriction`
- `dragForce`, `maxPower`, `baseMaxPowerLevel`

### Collision

- `BallBallCollision` / `getBallBallCollision:ballB:time:force:`
- `BallLineCollision` / `getBallLineCollision:angle:time:force:pointA:pointB:`

### Exportهای debug (قابل hook مستقیم)

| Symbol | آدرس (nm) | کار |
|--------|-----------|-----|
| `sPhysicsDebugEnabled` | `0x29939f1` | روشن کردن debug فیزیک |
| `sPhysicsDebugDiagnostics` | `0x29939fe` | تشخیص |
| `sInternalVelocity` | `0x2993a07` | سرعت داخلی (float) |

---

## ۶. Assets (base.apk — بدون lib)

| فایل | محتوا |
|------|--------|
| `Slice_formationList.plist` | 52 formation — موقعیت مهره‌ها |
| `Slice_adaGeneralConfig.plist` | ADA aim assist — maxForce, minForce |
| `assets/unpack/*Field*` | اسکین زمین |

Formation نمونه: `diamond`, `eagle`, `psycho`, `bow`, ...

---

## ۷. ابزارهای پروژه

```bash
cd tools/physics-dump

# 1) استخراج lib از so.apks
./scripts/extract_lib_from_apks.sh

# 2) تحلیل استاتیک خودکار → lib_analysis.json
python3 scripts/analyze_lib.py

# 3) نماد‌ها
./scripts/dump_symbols.sh

# 4) Frida (گوشی روت)
frida -U com.miniclip.soccerstars -l frida/hook_physics_pipeline.js
```

### Frida REPL

```javascript
rpc.enableDebug()      // sPhysicsDebugEnabled = 1
rpc.readExports()      // مقادیر فعلی
rpc.listSelectors()    // selectorهای ثبت‌شده
rpc.libBase()          // base address
```

---

## ۸. محدودیت‌ها (مهم برای bug bounty)

1. **آنلاین:** سرور `shot_outcome` را تعیین می‌کند — lib کلاینت فقط انیمیشن می‌زند.
2. **ثابت‌های فیزیک** داخل کلاس‌ها هستند — باید Ghidra + xref به `mFrictionFactor` یا Frida حافظه زنده.
3. **نسخه عوض شود** → offsetها و build number در نام lib (`Module-1013`) تغییر می‌کند.
4. **هدف مجاز:** تحلیل، overlay دسترسی‌پذیری، کالیبراسیون — نه تزریق شلیک آنلاین.

---

## ۹. خروجی JSON

گزارش کامل خودکار: `tools/physics-dump/dumped/lib_analysis.json`

---

## ۱۰. Ghidra — گام بعد

1. Import `libgame-SSM-GooglePlay-Gold-Release-Module-1013.so`
2. Analysis → Auto Analyze
3. Search → For Strings → `takeActualShot:angle:spin:playSound:`
4. Show References → تابع والد
5. همان کلاس → `mFrictionFactor`, `mEdgeRestitutionFactor`
6. آدرس تابع → `Interceptor.attach(base.add(OFFSET), ...)`
