# Research Bridge — دسترسی ریموت امن به گوشی روت‌شده

**هدف:** اتصال Cloud Agent به گوشی شما از طریق VPS با **Termux + SSH reverse tunnel**  
(ADB / Frida / logcat / باز کردن بازی) — **بدون** ساخت APK ریموت‌کنترل (RAT).

> این ابزار فقط برای تحقیق آموزشی روی دستگاه خودتان است.

## چرا APK ریموت‌کنترل نمی‌سازیم؟

یک APK که کل گوشی را از راه دور کنترل کند شبیه malware است.  
راه‌حل استاندارد امنیتی/RE: **Termux + SSH + ADB over TCP**.

## معماری

```
[گوشی روت + Termux]  --SSH reverse-->  [VPS]  <--SSH key--  [Cloud Agent]
   ADB :5555              :15555
   sshd:8022              :18022
   Frida:27042            :27042
```

## ۱) آماده‌سازی VPS (یک‌بار)

روی سرور (به‌عنوان root):

```bash
# اسکریپت را از ریپو کپی کنید یا:
curl -fsSL ... | bash   # یا دستی از tools/research-bridge/vps/setup-vps.sh
bash setup-vps.sh
```

کلید عمومی Agent را به `/root/.ssh/authorized_keys` اضافه کنید  
(`tools/research-bridge/keys/agent_ed25519.pub`).

**امنیت:** اگر پسورد root را در چت فرستاده‌اید، **فوراً عوض کنید**:
```bash
passwd
```

## ۲) گوشی — Termux

1. نصب **Termux** (+ اختیاری Termux:API / Termux:Boot)
2. کپی پوشه `tools/research-bridge/termux` به `~/research-bridge/`
3. کپی `config.example.env` → `~/research-bridge/config.env` و `VPS_HOST` را پر کنید
4. کلید عمومی Termux را به VPS اضافه کنید:
   ```bash
   bash setup.sh
   cat ~/.ssh/id_ed25519.pub
   # → VPS authorized_keys
   ```
5. ADB روی TCP:
   ```bash
   su -c 'setprop service.adb.tcp.port 5555; stop adbd; start adbd'
   ```
6. پل را روشن نگه دارید:
   ```bash
   bash start-bridge.sh
   ```

با **Termux:Boot** می‌توانید `start-bridge.sh` را خودکار اجرا کنید.

## ۳) Agent / لپ‌تاپ

```bash
cp tools/research-bridge/config.example.env tools/research-bridge/config.env
# VPS_HOST و مسیر کلید را تنظیم کنید

chmod +x tools/research-bridge/agent/remote.sh
./tools/research-bridge/agent/remote.sh status
./tools/research-bridge/agent/remote.sh adb-connect
./tools/research-bridge/agent/remote.sh launch-game
./tools/research-bridge/agent/remote.sh logcat
./tools/research-bridge/agent/remote.sh pull-dump ./dump.json
```

## چک‌لیست وقتی کار نمی‌کند

| علامت | کار |
|-------|-----|
| `ssm-bridge-status` هیچ پورتی ندارد | `start-bridge.sh` روی گوشی باز است؟ |
| `adb devices` خالی | `adb tcpip` / `setprop service.adb.tcp.port` |
| SSH refuse | کلید Termux در VPS؟ فایروال پورت ۲۲؟ |
| Frida fail | `frida-server` در `/data/local/tmp` و روت |

## فایل‌های مهم

| مسیر | نقش |
|------|-----|
| `termux/setup.sh` | نصب پکیج‌های Termux |
| `termux/start-bridge.sh` | تونل معکوس دائمی |
| `vps/setup-vps.sh` | آماده‌سازی سرور |
| `agent/remote.sh` | دستورات از سمت Agent |
| `config.example.env` | نمونه تنظیمات (بدون پسورد) |

`config.env` و کلید خصوصی **commit نمی‌شوند**.
