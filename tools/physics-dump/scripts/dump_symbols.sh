#!/usr/bin/env bash
# Dump interesting native symbols/strings from extracted Soccer Stars lib.
set -euo pipefail

LIB="${1:-./dumped/libgame-SSM.so}"
OUT="${2:-./dumped/symbols.txt}"

if [[ ! -f "$LIB" ]]; then
  echo "Library not found: $LIB"
  echo "Run: ./extract_lib_from_apks.sh first"
  exit 1
fi

mkdir -p "$(dirname "$OUT")"

{
  echo "# Soccer Stars native lib symbol dump"
  echo "# file: $LIB"
  echo "# date: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo ""

  echo "## Exported physics/debug symbols (nm -D)"
  nm -D "$LIB" 2>/dev/null | rg -i 'physics|friction|restitution|velocity|shot|power|collision' || true

  echo ""
  echo "## Key strings"
  strings "$LIB" | rg -i \
    'takeActualShot|mFrictionFactor|mEdgeRestitution|shot_taken|sPhysicsDebug|dragForce|maxPower|BallBallCollision|BallLineCollision' \
    | sort -u || true
} > "$OUT"

echo "Wrote $OUT ($(wc -l < "$OUT") lines)"
