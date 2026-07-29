#!/usr/bin/env bash
# Extract Soccer Stars native game library from a local .apks bundle or download URL.
#
# Usage:
#   ./extract_lib_from_apks.sh                          # download default URL
#   ./extract_lib_from_apks.sh /path/to/so.apks         # local file
#   ./extract_lib_from_apks.sh /path/to/so.apks ./out   # custom output dir
#
# Default download: https://dl.mr-cheat.ir/so.apks
set -euo pipefail

DEFAULT_URL="https://dl.mr-cheat.ir/so.apks"
LIB_PATTERN="lib/arm64-v8a/libgame-SSM*.so"

INPUT="${1:-$DEFAULT_URL}"
OUT_DIR="${2:-./dumped}"
WORK_DIR="${OUT_DIR}/.extract_tmp"

mkdir -p "$OUT_DIR"
rm -rf "$WORK_DIR"
mkdir -p "$WORK_DIR"

cleanup() { rm -rf "$WORK_DIR"; }
trap cleanup EXIT

resolve_apks() {
  if [[ -f "$INPUT" ]]; then
    echo "[*] Using local file: $INPUT"
    cp "$INPUT" "$WORK_DIR/bundle.apks"
    return
  fi

  if [[ "$INPUT" =~ ^https?:// ]]; then
    echo "[*] Downloading: $INPUT"
    curl -fsSL --progress-bar -o "$WORK_DIR/bundle.apks" "$INPUT"
    return
  fi

  echo "Input not found and not a URL: $INPUT"
  exit 1
}

extract_split() {
  echo "[1/4] Unpacking .apks bundle..."
  unzip -q -o "$WORK_DIR/bundle.apks" -d "$WORK_DIR"

  local split=""
  for candidate in \
    "$WORK_DIR/split_config.arm64_v8a.apk" \
    "$WORK_DIR/split_config.arm64-v8a.apk"; do
    if [[ -f "$candidate" ]]; then
      split="$candidate"
      break
    fi
  done

  if [[ -z "$split" ]]; then
  # Fallback: search any split containing native libs
    split=$(find "$WORK_DIR" -maxdepth 1 -name 'split_*.apk' -print | head -1)
  fi

  if [[ -z "$split" || ! -f "$split" ]]; then
    echo "Could not find arm64 split APK inside bundle."
    exit 1
  fi

  echo "[2/4] Found native split: $(basename "$split")"
  unzip -q -o "$split" "$LIB_PATTERN" -d "$WORK_DIR"
}

copy_lib() {
  local lib
  lib=$(find "$WORK_DIR" -path "*/libgame-SSM*.so" | head -1)

  if [[ -z "$lib" ]]; then
    echo "libgame-SSM*.so not found in split APK."
    exit 1
  fi

  local basename
  basename=$(basename "$lib")
  local dest="$OUT_DIR/$basename"

  echo "[3/4] Copying $basename ($(du -h "$lib" | cut -f1))"
  cp "$lib" "$dest"

  # Convenience symlink for Ghidra / Frida scripts expecting short name
  ln -sf "$basename" "$OUT_DIR/libgame-SSM.so"

  echo "[4/4] Writing metadata..."
  {
    echo "source=$INPUT"
    echo "lib=$basename"
    echo "size_bytes=$(stat -c%s "$dest" 2>/dev/null || stat -f%z "$dest")"
    echo "extracted_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  } > "$OUT_DIR/libgame-SSM.meta"

  echo ""
  echo "Done."
  echo "  Library : $dest"
  echo "  Symlink : $OUT_DIR/libgame-SSM.so"
  echo ""
  echo "Next steps:"
  echo "  nm -D \"$dest\" | rg -i 'physics|friction|shot'"
  echo "  strings \"$dest\" | rg 'takeActualShot|mFrictionFactor'"
  echo "  Open in Ghidra: $dest"
}

resolve_apks
extract_split
copy_lib
