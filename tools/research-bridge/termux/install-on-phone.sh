#!/data/data/com.termux/files/usr/bin/bash
# Install Termux bridge + optional Termux:Boot autostart
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
DEST="$HOME/research-bridge"
mkdir -p "$DEST" "$HOME/.termux/boot"
cp -f "$ROOT/setup.sh" "$ROOT/start-bridge.sh" "$DEST/"
chmod +x "$DEST"/*.sh
if [[ -f "$ROOT/config.env" ]]; then
  cp -f "$ROOT/config.env" "$DEST/config.env"
elif [[ ! -f "$DEST/config.env" ]]; then
  echo "Create $DEST/config.env from config.example.env first"
  exit 1
fi
bash "$DEST/setup.sh"
if [[ -f "$ROOT/termux-boot-ssm-bridge.sh" ]]; then
  cp -f "$ROOT/termux-boot-ssm-bridge.sh" "$HOME/.termux/boot/ssm-bridge"
  chmod +x "$HOME/.termux/boot/ssm-bridge"
  echo "[ok] Termux:Boot script installed → ~/.termux/boot/ssm-bridge"
  echo "     Install app Termux:Boot from F-Droid, open it once, then reboot."
fi
echo
echo "Start now: bash $DEST/start-bridge.sh"
echo "Keep screen on or use: termux-wake-lock"
