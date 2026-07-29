# Tesf — Soccer Stars Bug Bounty / Assist Research

پروژه تحلیل و ابزار کمکی برای **Soccer Stars** (`com.miniclip.soccerstars`).

## استخراج lib بازی از APK

فایل `so.apks` (نسخه 36.14.4):

```
https://dl.mr-cheat.ir/so.apks
```

### سریع

```bash
cd tools/physics-dump
chmod +x scripts/*.sh
./scripts/extract_lib_from_apks.sh
```

خروجی: `tools/physics-dump/dumped/libgame-SSM-GooglePlay-Gold-Release-Module-1013.so`

### مستندات کامل

- [tools/physics-dump/README_FA.md](tools/physics-dump/README_FA.md) — Frida، Ghidra، کالیبراسیون فیزیک
- [game-analysis/ANALYSIS.md](game-analysis/ANALYSIS.md) — تحلیل APK و پروتکل شبکه

## اپ Android Assist

```bash
cd soccer-stars-android
./gradlew assembleDebug
```

APKهای release در `releases/` و `soccer-stars-android/releases/`.
