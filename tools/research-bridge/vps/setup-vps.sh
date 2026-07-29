#!/usr/bin/env bash
# VPS: prepare reverse-tunnel relay for research ADB/Frida
set -euo pipefail

ADB_PORT="${VPS_ADB_PORT:-15555}"
TERMUX_PORT="${VPS_TERMUX_SSH_PORT:-18022}"
FRIDA_PORT="${VPS_FRIDA_PORT:-27042}"

echo "[vps] installing packages..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq openssh-server android-tools-adb android-tools-fastboot \
  python3 python3-pip curl wget unzip adb 2>/dev/null || \
  apt-get install -y -qq openssh-server android-tools-adb python3 python3-pip curl wget unzip

pip3 install --quiet frida-tools 2>/dev/null || true

mkdir -p /root/.ssh
chmod 700 /root/.ssh
touch /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys

SSHD_CFG=/etc/ssh/sshd_config
backup="${SSHD_CFG}.bak.research"
[[ -f "$backup" ]] || cp "$SSHD_CFG" "$backup"

# Allow reverse tunnels from phone; do NOT bind to public 0.0.0.0 by default
grep -q '^GatewayPorts' "$SSHD_CFG" && sed -i 's/^GatewayPorts.*/GatewayPorts clientspecified/' "$SSHD_CFG" \
  || echo 'GatewayPorts clientspecified' >> "$SSHD_CFG"
grep -q '^AllowTcpForwarding' "$SSHD_CFG" && sed -i 's/^AllowTcpForwarding.*/AllowTcpForwarding yes/' "$SSHD_CFG" \
  || echo 'AllowTcpForwarding yes' >> "$SSHD_CFG"
grep -q '^ClientAliveInterval' "$SSHD_CFG" || echo 'ClientAliveInterval 30' >> "$SSHD_CFG"
grep -q '^ClientAliveCountMax' "$SSHD_CFG" || echo 'ClientAliveCountMax 3' >> "$SSHD_CFG"

systemctl enable ssh 2>/dev/null || systemctl enable sshd 2>/dev/null || true
systemctl restart ssh 2>/dev/null || systemctl restart sshd 2>/dev/null || service ssh restart || true

cat > /usr/local/bin/ssm-bridge-status <<'EOF'
#!/bin/bash
echo "=== listening reverse ports ==="
ss -lntp | grep -E ':(15555|18022|27042)\b' || echo "(none yet — start Termux bridge on phone)"
echo
echo "=== adb devices ==="
adb devices -l 2>/dev/null || true
EOF
chmod +x /usr/local/bin/ssm-bridge-status

cat > /usr/local/bin/ssm-adb-connect <<EOF
#!/bin/bash
adb disconnect 127.0.0.1:${ADB_PORT} 2>/dev/null || true
adb connect 127.0.0.1:${ADB_PORT}
adb devices -l
EOF
chmod +x /usr/local/bin/ssm-adb-connect

echo "[vps] ready."
echo "  status:  ssm-bridge-status"
echo "  adb:     ssm-adb-connect   # after phone tunnel is up"
echo "  shell:   ssh -p ${TERMUX_PORT} u0_aXXX@127.0.0.1  # Termux user"
echo "  frida:   frida -H 127.0.0.1:${FRIDA_PORT} -l script.js"
