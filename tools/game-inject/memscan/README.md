# ssm_memscan

GameGuardian-style read-only heap scanner for Soccer Stars (aarch64).

```bash
# build
$NDK/.../aarch64-linux-android28-clang -O2 -fPIE -pie -o build/ssm_memscan ssm_memscan.c -lm

# run as root on device
ssm_memscan <pid> [home away]
# writes /sdcard/Download/ssm_memscan.json
```
