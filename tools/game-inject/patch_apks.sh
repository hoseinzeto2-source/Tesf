#!/usr/bin/env bash
# Build Dear ImGui research HUD and patch Soccer Stars so.apks (local / educational use).
# Dex-only patch: avoids apktool resource rebuild (invalid $applovin drawable names).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
INJECT_DIR="$ROOT/tools/game-inject"
INPUT="${1:-$ROOT/downloads/so.apks}"
OUT_DIR="${2:-$ROOT/build/patched-output}"
ANDROID_HOME="${ANDROID_HOME:-/workspace/android-sdk}"
NDK="$ANDROID_HOME/ndk/26.1.10909125"
BT="$ANDROID_HOME/build-tools/34.0.0"

KEYSTORE="$INJECT_DIR/keys/research.keystore"
KS_PASS="android"
KEY_ALIAS="research"

# Remove only JAR signature files before re-signing. Do NOT delete META-INF/services/*
# (Kotlin BuiltInsLoader, Ktor, etc.) — stripping all META-INF/* causes Play 1v1 crash.
strip_jar_signatures() {
  local apk="$1"
  while IFS= read -r entry; do
  [[ -n "$entry" ]] && zip -q -d "$apk" "$entry" || true
  done < <(zipinfo -1 "$apk" | grep -E '^META-INF/.*\.(SF|RSA|DSA)$|^META-INF/MANIFEST\.MF$' || true)
}

if [[ ! -f "$INPUT" ]]; then
  echo "Input not found: $INPUT"
  echo "Download: curl -L -o downloads/so.apks https://dl.mr-cheat.ir/so.apks"
  exit 1
fi

command -v baksmali >/dev/null || { echo "baksmali not found (apt install smali)"; exit 1; }
command -v smali >/dev/null || { echo "smali not found (apt install smali)"; exit 1; }

echo "[1/7] Building native lib (Dear ImGui + egl hook)..."
cmake -B "$INJECT_DIR/native/build" \
  -DCMAKE_TOOLCHAIN_FILE="$NDK/build/cmake/android.toolchain.cmake" \
  -DANDROID_ABI=arm64-v8a \
  -DANDROID_PLATFORM=android-26 \
  -DCMAKE_BUILD_TYPE=Release \
  "$INJECT_DIR/native"
cmake --build "$INJECT_DIR/native/build" -j"$(nproc)"

LIB_SO="$INJECT_DIR/native/build/libssm_research_hud.so"
if [[ ! -f "$LIB_SO" ]]; then
  echo "Build failed: $LIB_SO"
  exit 1
fi

mkdir -p "$OUT_DIR/work" "$INJECT_DIR/keys"
WORK="$OUT_DIR/work"

echo "[2/7] Unpacking $INPUT ..."
rm -rf "$WORK/apks" "$WORK/dex-patch"
mkdir -p "$WORK/apks"
unzip -q -o "$INPUT" -d "$WORK/apks"

echo "[3/7] Dex-only patch: classes6.dex (MultiDexApplication) ..."
mkdir -p "$WORK/dex-patch"
unzip -q -o "$WORK/apks/base.apk" classes6.dex -d "$WORK/dex-patch"
baksmali d "$WORK/dex-patch/classes6.dex" -o "$WORK/dex-patch/smali"

SMALI="$WORK/dex-patch/smali/androidx/multidex/MultiDexApplication.smali"
if ! grep -q "ssm_research_hud" "$SMALI"; then
  SMALI_PATH="$SMALI" python3 <<'PY'
import os
from pathlib import Path
p = Path(os.environ["SMALI_PATH"])
text = p.read_text()
old = """    invoke-static {p0}, Landroidx/multidex/MultiDex;->install(Landroid/content/Context;)V

    return-void"""
new = """    invoke-static {p0}, Landroidx/multidex/MultiDex;->install(Landroid/content/Context;)V

    const-string v0, "ssm_research_hud"

    invoke-static {v0}, Ljava/lang/System;->loadLibrary(Ljava/lang/String;)V

    return-void"""
if old not in text:
    raise SystemExit("smali patch anchor not found in MultiDexApplication.attachBaseContext")
p.write_text(text.replace(old, new, 1))
PY
fi

smali a "$WORK/dex-patch/smali" -o "$WORK/dex-patch/classes6.dex"

echo "[4/7] Repacking base.apk (replace dex, keep binary resources) ..."
cp "$WORK/apks/base.apk" "$WORK/base-patched.apk"
strip_jar_signatures "$WORK/base-patched.apk"
(cd "$WORK/dex-patch" && zip -q -u "$WORK/base-patched.apk" classes6.dex)

echo "[5/7] Injecting native lib into arm64 split (STORED, no re-compress) ..."
cp "$WORK/apks/split_config.arm64_v8a.apk" "$WORK/split-patched.apk"
strip_jar_signatures "$WORK/split-patched.apk"
mkdir -p "$WORK/split-add/lib/arm64-v8a"
cp "$LIB_SO" "$WORK/split-add/lib/arm64-v8a/libssm_research_hud.so"
(cd "$WORK/split-add" && zip -q -0 -u "$WORK/split-patched.apk" lib/arm64-v8a/libssm_research_hud.so)

if [[ ! -f "$KEYSTORE" ]]; then
  echo "[*] Creating debug keystore for research build ..."
  keytool -genkey -v -keystore "$KEYSTORE" -alias "$KEY_ALIAS" \
    -keyalg RSA -keysize 2048 -validity 10000 \
    -storepass "$KS_PASS" -keypass "$KS_PASS" \
    -dname "CN=University Bug Bounty Research, OU=Education, O=Local, C=US"
fi

sign_apk() {
  local apk="$1"
  # -p page-aligns .so entries — required when android:extractNativeLibs="false"
  "$BT/zipalign" -p -f 4 "$apk" "$apk.aligned"
  mv "$apk.aligned" "$apk"
  "$BT/apksigner" sign --ks "$KEYSTORE" --ks-pass "pass:$KS_PASS" \
    --key-pass "pass:$KS_PASS" --ks-key-alias "$KEY_ALIAS" "$apk"
}

echo "[6/7] Signing APKs ..."
sign_apk "$WORK/base-patched.apk"
sign_apk "$WORK/split-patched.apk"

OUT_APKS="$OUT_DIR/soccer-stars-research-hud.apks"
rm -f "$OUT_APKS"
(cd "$WORK" && zip -q "$OUT_APKS" base-patched.apk split-patched.apk)

echo "[7/7] Done."
echo ""
echo "Output: $OUT_APKS"
echo ""
echo "Install (all splits required):"
echo "  adb install-multiple $WORK/base-patched.apk $WORK/split-patched.apk"
echo "Or use SAI / Split APKs Installer with: $OUT_APKS"
echo ""
echo "Educational READ-ONLY ImGui HUD — no shot injection."
