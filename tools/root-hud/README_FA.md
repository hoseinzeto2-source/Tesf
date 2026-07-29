# Root HUD — خواندن مشخصات از خود بازی (Frida)

برای گوشی **روت** — داده مستقیم از پروسه `com.miniclip.soccerstars` (read-only).

## این ≠ تزریق ImGui داخل so.apks

| | Assist APK (overlay) | Root Frida (این فولدر) | Patch so.apks |
|--|---------------------|------------------------|---------------|
| نصب | APK جدا | frida-server | mod بازی |
| کرش بازی | خیر | معمولاً خیر | ممکن |
| آنتی‌چیت | کم | متوسط (read-only) | **بالا** |
| ImGui visual | پنل Canvas | لاگ / REPL | native در بازی |

## نصب Frida روی گوشی روت

```bash
# PC
pip install frida-tools

# گوشی: frida-server متناسب arm64 از github.com/frida/frida/releases
adb push frida-server /data/local/tmp/
adb shell su -c "chmod 755 /data/local/tmp/frida-server"
adb shell su -c "/data/local/tmp/frida-server -D &"
```

## اجرا در مسابقه

```bash
cd tools/root-hud
frida -U com.miniclip.soccerstars -l frida/root_live_telemetry.js
```

```javascript
rpc.enablePhysicsDebug()
rpc.snapshot()
rpc.startPolling(2000)
```

## خروجی

- `bodies[]` — x, y, vx, vy (نرمال 0..1)
- `physics_exports` — sInternalVelocity, debug flags
- `selectors_seen` — takeActualShot, ballPositionInPoints, ...

## امنیت / بن

- اسکریپت **فقط می‌خواند** — شلیک یا شبکه دستکاری نمی‌شود
- آنلاین: امتیاز واقعی از سرور است
- patch کردن `so.apks` برای آنلاین **توصیه نمی‌شود**

## APK با ImGui (overlay — بدون روت)

https://github.com/hoseinzeto2-source/Tesf/raw/cursor/soccer-stars-lib-dump-b2df/releases/SoccerStarsAssist-v1.2.4.apk
