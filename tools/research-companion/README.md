# SSM Research Companion APK

User-facing Android app (not a stealth RAT):

- Button **اتصال** → reverse SSH to VPS + enable ADB TCP (root)
- Foreground service with notification
- Optional auto-start after reboot
- Button to open Soccer Stars

Agent then uses VPS:
```bash
ssm-phone status
ssm-adb-connect
adb shell ...
```

Build:
```bash
cd tools/research-companion
# place RSA private key at app/src/main/assets/id_rsa (gitignored)
./gradlew :app:assembleRelease
```

Output: `app/build/outputs/apk/release/app-release.apk`
