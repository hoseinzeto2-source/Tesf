#!/data/data/com.termux/files/usr/bin/bash
# Termux: one-time setup for Soccer Stars research bridge
set -euo pipefail

echo "[1/5] Updating packages..."
pkg update -y
pkg install -y openssh android-tools curl wget python nmap termux-api 2>/dev/null || \
  pkg install -y openssh android-tools curl wget python

echo "[2/5] Optional: Frida tools (pip)..."
pip install --upgrade frida-tools 2>/dev/null || true

echo "[3/5] SSH key for VPS (if missing)..."
mkdir -p "$HOME/.ssh"
chmod 700 "$HOME/.ssh"
if [[ ! -f "$HOME/.ssh/id_ed25519" ]]; then
  ssh-keygen -t ed25519 -f "$HOME/.ssh/id_ed25519" -N "" -C "termux-research-bridge"
fi

echo "[4/5] Start Termux sshd (optional shell reverse)..."
if ! pgrep -x sshd >/dev/null 2>&1; then
  sshd || true
fi

echo "[5/5] Done."
echo
echo "Next:"
echo "  1) Copy your public key to VPS:"
echo "       cat ~/.ssh/id_ed25519.pub"
echo "     → add to VPS /root/.ssh/authorized_keys"
echo "  2) Edit: nano ~/research-bridge/config.env"
echo "  3) Run:  bash ~/research-bridge/start-bridge.sh"
echo
echo "Enable ADB over TCP (rooted):"
echo "  su -c 'setprop service.adb.tcp.port 5555; stop adbd; start adbd'"
