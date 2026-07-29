#!/usr/bin/env bash
# From cloud agent / laptop: talk to phone via VPS reverse tunnels
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CONFIG="${RESEARCH_BRIDGE_CONFIG:-$ROOT/config.env}"
if [[ ! -f "$CONFIG" ]]; then
  echo "Missing $CONFIG — copy config.example.env"
  exit 1
fi
# shellcheck disable=SC1090
source "$CONFIG"

KEY="${SSH_KEY:-$ROOT/keys/agent_ed25519}"
VPS_HOST="${VPS_HOST:?}"
VPS_PORT="${VPS_PORT:-22}"
VPS_USER="${VPS_USER:-root}"
VPS_ADB_PORT="${VPS_ADB_PORT:-15555}"
VPS_TERMUX_SSH_PORT="${VPS_TERMUX_SSH_PORT:-18022}"
VPS_FRIDA_PORT="${VPS_FRIDA_PORT:-27042}"

SSH_BASE=(ssh -i "$KEY" -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new -p "$VPS_PORT" "${VPS_USER}@${VPS_HOST}")

cmd="${1:-status}"
shift || true

case "$cmd" in
  status)
    "${SSH_BASE[@]}" ssm-bridge-status
    ;;
  adb-connect)
    "${SSH_BASE[@]}" ssm-adb-connect
    ;;
  adb)
    # adb <args...> via VPS
    "${SSH_BASE[@]}" "adb $*"
    ;;
  shell)
    "${SSH_BASE[@]}"
    ;;
  logcat)
    "${SSH_BASE[@]}" "adb logcat -s SSMResearchHUD:V *:S"
    ;;
  launch-game)
    "${SSH_BASE[@]}" "adb shell am start -n com.miniclip.soccerstars/com.miniclip.soccerstars.SoccerStarsActivity || adb shell monkey -p com.miniclip.soccerstars -c android.intent.category.LAUNCHER 1"
    ;;
  pull-dump)
    out="${1:-./ssm_research_dump.json}"
    "${SSH_BASE[@]}" "adb shell 'cat /sdcard/Download/ssm_research_dump.json 2>/dev/null || cat /storage/emulated/0/Download/ssm_research_dump.json'" > "$out"
    echo "saved $out"
    ;;
  frida)
    echo "Use on VPS: frida -H 127.0.0.1:${VPS_FRIDA_PORT} $*"
    "${SSH_BASE[@]}" "frida -H 127.0.0.1:${VPS_FRIDA_PORT} $*"
    ;;
  *)
    echo "Usage: $0 {status|adb-connect|adb|shell|logcat|launch-game|pull-dump|frida} ..."
    exit 1
    ;;
esac
