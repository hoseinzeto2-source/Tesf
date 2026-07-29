#!/data/data/com.termux/files/usr/bin/bash
# Termux:Boot — auto-start research bridge after reboot
# Install: pkg install termux-boot
# Copy this file to: ~/.termux/boot/ssm-bridge
set -euo pipefail
sleep 8
termux-wake-lock 2>/dev/null || true
BRIDGE="$HOME/research-bridge/start-bridge.sh"
if [[ -x "$BRIDGE" ]]; then
  nohup bash "$BRIDGE" >> "$HOME/research-bridge/bridge.log" 2>&1 &
fi
