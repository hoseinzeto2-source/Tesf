#!/data/data/com.termux/files/usr/bin/bash
# Termux: keep reverse SSH tunnels alive → VPS
# Forwards: ADB (5555), Termux sshd (8022), Frida (27042)
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# Prefer ~/research-bridge/config.env when installed on phone
CONFIG="${RESEARCH_BRIDGE_CONFIG:-$HOME/research-bridge/config.env}"
if [[ ! -f "$CONFIG" ]]; then
  CONFIG="$SCRIPT_DIR/../config.env"
fi
if [[ ! -f "$CONFIG" ]]; then
  echo "Missing config.env — copy config.example.env and fill VPS_HOST / key auth"
  exit 1
fi
# shellcheck disable=SC1090
source "$CONFIG"

VPS_HOST="${VPS_HOST:?}"
VPS_PORT="${VPS_PORT:-22}"
VPS_USER="${VPS_USER:-root}"
VPS_ADB_PORT="${VPS_ADB_PORT:-15555}"
VPS_TERMUX_SSH_PORT="${VPS_TERMUX_SSH_PORT:-18022}"
VPS_FRIDA_PORT="${VPS_FRIDA_PORT:-27042}"
PHONE_ADB_PORT="${PHONE_ADB_PORT:-5555}"
PHONE_SSH_PORT="${PHONE_SSH_PORT:-8022}"
PHONE_FRIDA_PORT="${PHONE_FRIDA_PORT:-27042}"

ensure_adb_tcp() {
  if command -v su >/dev/null 2>&1; then
    su -c "setprop service.adb.tcp.port ${PHONE_ADB_PORT}; stop adbd; start adbd" 2>/dev/null || true
  fi
  if command -v adb >/dev/null 2>&1; then
    adb start-server 2>/dev/null || true
  fi
}

ensure_sshd() {
  if ! pgrep -x sshd >/dev/null 2>&1; then
    sshd 2>/dev/null || true
  fi
}

ensure_frida() {
  # Start frida-server if present (Magisk / /data/local/tmp)
  if command -v su >/dev/null 2>&1; then
    su -c "pkill -9 frida-server 2>/dev/null; true"
    for p in /data/local/tmp/frida-server /data/local/tmp/fs \
             /data/adb/frida-server; do
      if su -c "test -x $p"; then
        su -c "$p -l 0.0.0.0:${PHONE_FRIDA_PORT} &" 2>/dev/null || true
        break
      fi
    done
  fi
}

SSH_OPTS=(
  -N
  -o ServerAliveInterval=30
  -o ServerAliveCountMax=3
  -o ExitOnForwardFailure=yes
  -o StrictHostKeyChecking=accept-new
  -p "$VPS_PORT"
  -R "127.0.0.1:${VPS_ADB_PORT}:127.0.0.1:${PHONE_ADB_PORT}"
  -R "127.0.0.1:${VPS_TERMUX_SSH_PORT}:127.0.0.1:${PHONE_SSH_PORT}"
  -R "127.0.0.1:${VPS_FRIDA_PORT}:127.0.0.1:${PHONE_FRIDA_PORT}"
)

echo "[bridge] enabling adb tcp / sshd / frida..."
ensure_adb_tcp
ensure_sshd
ensure_frida

echo "[bridge] reverse tunnels → ${VPS_USER}@${VPS_HOST}"
echo "  ADB    VPS:${VPS_ADB_PORT}  ← phone:${PHONE_ADB_PORT}"
echo "  SSH    VPS:${VPS_TERMUX_SSH_PORT} ← phone:${PHONE_SSH_PORT}"
echo "  Frida  VPS:${VPS_FRIDA_PORT} ← phone:${PHONE_FRIDA_PORT}"
echo "[bridge] keep this Termux session open (or use Termux:Boot)"

while true; do
  ssh "${SSH_OPTS[@]}" "${VPS_USER}@${VPS_HOST}" || true
  echo "[bridge] tunnel dropped — retry in 5s..."
  sleep 5
done
