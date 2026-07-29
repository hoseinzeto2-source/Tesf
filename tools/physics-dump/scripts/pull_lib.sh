#!/usr/bin/env bash
# Pull Soccer Stars native library from a rooted phone via adb.
set -euo pipefail

PKG="com.miniclip.soccerstars"
OUT_DIR="${1:-./dumped}"

mkdir -p "$OUT_DIR"

echo "[1/3] Finding native library path on device..."
LIB_PATH=$(adb shell su -c "find /data/app -path '*${PKG}*/lib/arm64/libgame-SSM*.so 2>/dev/null | head -1" | tr -d '\r')

if [[ -z "$LIB_PATH" ]]; then
  LIB_PATH=$(adb shell su -c "pm path ${PKG}" | tr -d '\r' | head -1 | sed 's/package://')
  APK_DIR=$(dirname "$LIB_PATH")
  LIB_PATH=$(adb shell su -c "find ${APK_DIR} -name 'libgame-SSM*.so' 2>/dev/null | head -1" | tr -d '\r')
fi

if [[ -z "$LIB_PATH" ]]; then
  echo "Could not find libgame-SSM*.so. Is Soccer Stars installed?"
  exit 1
fi

echo "Found: $LIB_PATH"

echo "[2/3] Pulling library..."
adb shell su -c "cat '$LIB_PATH'" > "$OUT_DIR/libgame-SSM.so"

echo "[3/3] Pulling APK splits (optional metadata)..."
adb shell su -c "pm path ${PKG}" | tr -d '\r' | while read -r line; do
  apk="${line#package:}"
  name=$(basename "$apk")
  adb shell su -c "cat '$apk'" > "$OUT_DIR/$name" || true
done

echo "Done. Output: $OUT_DIR/libgame-SSM.so"
echo "Analyze with: nm -D $OUT_DIR/libgame-SSM.so | rg Physics"
