#!/bin/bash
# FFTEdit stream worker installer (Ubuntu 22.04 / 24.04).
# Usage (as root, line is shown in the admin panel → Servers):
#   curl -fsSL https://ffteditt.store/worker/install.sh | bash -s -- https://ffteditt.store fft_xxxxxxxx
set -euo pipefail

PANEL="${1:-}"
TOKEN="${2:-}"

if [ "$(id -u)" != "0" ]; then
  echo "Please run as root (log in with: ssh root@YOUR_VPS_IP)"; exit 1
fi
if [[ ! "$PANEL" =~ ^https://[A-Za-z0-9.-]+$ ]] || [[ ! "$TOKEN" =~ ^fft_[a-f0-9]{48}$ ]]; then
  echo "Missing or wrong panel address / token. Copy the full line again from Admin → Servers."; exit 1
fi

echo "==> 1/4 Installing programs (ffmpeg, virtual screen, Chrome)…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q ffmpeg xvfb python3 python3-venv curl ca-certificates fonts-liberation fonts-noto-color-emoji pulseaudio pulseaudio-utils >/dev/null
if ! command -v google-chrome >/dev/null 2>&1; then
  curl -fsSL -o /tmp/chrome.deb https://dl.google.com/linux/direct/google-chrome-stable_current_amd64.deb
  apt-get install -y -q /tmp/chrome.deb >/dev/null
  rm -f /tmp/chrome.deb
fi

echo "==> 2/4 Downloading the worker…"
mkdir -p /opt/ffteditt-worker /var/lib/ffteditt-worker
curl -fsSL "$PANEL/worker/agent.py" -o /opt/ffteditt-worker/agent.py.new
python3 -m py_compile /opt/ffteditt-worker/agent.py.new
mv /opt/ffteditt-worker/agent.py.new /opt/ffteditt-worker/agent.py
chmod 755 /opt/ffteditt-worker/agent.py

echo "==> 3/4 Saving the secret token…"
( umask 077; printf '{"panel": "%s", "token": "%s"}\n' "$PANEL" "$TOKEN" > /etc/ffteditt-worker.json )

# stop the old manual test stream if it is still running
pkill -f chrome-stream 2>/dev/null || true
pkill -f "Xvfb :99" 2>/dev/null || true

echo "==> 4/4 Starting the worker service…"
cat > /etc/systemd/system/ffteditt-worker.service <<'UNIT'
[Unit]
Description=FFTEdit YouTube stream worker
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=/usr/bin/python3 /opt/ffteditt-worker/agent.py
Restart=always
RestartSec=5
KillMode=control-group
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable ffteditt-worker >/dev/null 2>&1
systemctl restart ffteditt-worker
sleep 3

if systemctl is-active --quiet ffteditt-worker; then
  echo ""
  echo "=============================================="
  echo " DONE! This server is now connected."
  echo " Go back to the admin panel → Servers."
  echo " It should show Online within 10 seconds."
  echo "=============================================="
  echo " See what it is doing:  journalctl -u ffteditt-worker -f"
else
  echo "The worker did not start. Show this to support:"
  journalctl -u ffteditt-worker -n 30 --no-pager
  exit 1
fi
