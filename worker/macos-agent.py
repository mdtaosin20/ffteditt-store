#!/usr/bin/env python3
"""FFTEdit macOS worker (Apple Silicon / macOS).

This is a separate macOS worker; it does not replace worker/agent.py.
Screen capture uses FFmpeg's avfoundation input and requires macOS Screen Recording
permission for the Terminal/Python process. The first version intentionally supports
one active stream at a time because macOS captures a shared desktop, not isolated Xvfb
screens. Do not run multiple stream assignments on this worker yet.
"""
import json
import os
import platform
import shutil
import signal
import socket
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request

VERSION = "0.1.0-macos"
CONF_FILE = os.environ.get("FFT_CONF", os.path.expanduser("~/Library/Application Support/FFTEdit/worker.json"))
POLL = 5
running = True
stream_proc = None
stream_cfg = None
stream_status = "stopped"
stream_error = None
stream_started = None
stream_fps = None
stream_speed = None
chrome_proc = None


def log(msg):
    print(time.strftime("%Y-%m-%d %H:%M:%S"), msg, flush=True)


def redact(s):
    import re
    return re.sub(r"(rtmps?://[^\s/]+/[^\s/]+/)\S+", r"\1****", s)


def run(cmd, timeout=10):
    return subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)


def machine_info():
    vm = {}
    try:
        out = run(["sysctl", "-n", "hw.memsize"]).stdout.strip()
        vm["mem_total_mb"] = int(out) // (1024 * 1024)
        vm["mem_used_mb"] = max(0, vm["mem_total_mb"] - int(run(["vm_stat"]).stdout.split("Pages free:")[1].splitlines()[0].strip().split(".")[0].replace(" ", "")) * 4096 // (1024 * 1024))
    except Exception:
        pass
    info = {
        "hostname": socket.gethostname(),
        "cpus": os.cpu_count() or 0,
        "load1": "%.2f" % (os.getloadavg()[0] if hasattr(os, "getloadavg") else 0),
        "agent_version": VERSION,
        "uptime_s": 0,
    }
    try:
        boot = int(run(["sysctl", "-n", "kern.boottime"]).stdout.split("sec =")[1].split(",")[0].strip())
        info["uptime_s"] = max(0, int(time.time()) - boot)
    except Exception:
        pass
    info.update(vm)
    try:
        disk = shutil.disk_usage("/")
        info["disk_total_gb"] = round(disk.total / 1024**3, 1)
        info["disk_used_gb"] = round(disk.used / 1024**3, 1)
    except Exception:
        pass
    try:
        info["cpu_pct"] = round(float(run(["/usr/bin/osascript", "-e", 'do shell script "top -l 2 -n 0 | grep \\\"CPU usage\\\" | tail -1"']).stdout.split("user")[0].split(":")[-1].strip().replace("%", "") or 0), 1)
    except Exception:
        pass
    return info


def call_panel(panel, token, reports):
    body = json.dumps({"token": token, "info": machine_info(), "streams": reports,
                       "command_results": [], "voice_results": [], "voice_error": None,
                       "chat": [], "robot_results": []}).encode()
    req = urllib.request.Request(panel.rstrip("/") + "/api/worker.php", data=body,
        headers={"Content-Type": "application/json", "User-Agent": "ffteditt-macos-worker/" + VERSION},
        method="POST")
    with urllib.request.urlopen(req, timeout=20) as r:
        return json.loads(r.read().decode())


def open_game(url):
    global chrome_proc
    # Use a separate Chrome app window; macOS Screen Recording permission is required.
    chrome = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
    if os.path.exists(chrome):
        chrome_proc = subprocess.Popen([chrome, "--new-window", "--autoplay-policy=no-user-gesture-required", url],
                                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    else:
        subprocess.Popen(["open", "-a", "Google Chrome", url], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def stop_stream():
    global stream_proc, stream_cfg, stream_status, stream_error, stream_started, stream_fps, stream_speed, chrome_proc
    if stream_proc and stream_proc.poll() is None:
        stream_proc.terminate()
        try:
            stream_proc.wait(timeout=8)
        except subprocess.TimeoutExpired:
            stream_proc.kill()
    stream_proc = None
    stream_cfg = None
    stream_status = "stopped"
    stream_error = None
    stream_started = None
    stream_fps = stream_speed = None
    # Do not force-quit Chrome: the user may have other windows open.
    chrome_proc = None


def start_stream(cfg):
    global stream_proc, stream_cfg, stream_status, stream_error, stream_started, stream_fps, stream_speed
    if stream_proc and stream_proc.poll() is None:
        return
    if not shutil.which("ffmpeg"):
        raise RuntimeError("FFmpeg not found in PATH")
    if not cfg.get("rtmp") or not cfg.get("page_url"):
        raise RuntimeError("Missing stream URL or game page URL")
    # macOS captures the shared desktop. Only one stream is allowed on this worker.
    open_game(cfg["page_url"])
    log("[stream %s] opening game page; allow Screen Recording permission if macOS asks" % cfg.get("id"))
    time.sleep(8)
    w, h = int(cfg.get("width", 1280)), int(cfg.get("height", 720))
    fps, kbps = int(cfg.get("fps", 30)), int(cfg.get("kbps", 2500))
    gop = str(fps * 2)
    cmd = ["ffmpeg", "-hide_banner", "-loglevel", "warning", "-nostats", "-progress", "pipe:1",
           "-f", "avfoundation", "-framerate", str(fps), "-capture_cursor", "0",
           "-i", "1:none", "-f", "lavfi", "-i", "anullsrc=r=44100:cl=stereo",
           "-vf", "scale=%d:%d" % (w, h), "-c:v", "libx264", "-preset", "veryfast",
           "-tune", "zerolatency", "-pix_fmt", "yuv420p", "-b:v", "%dk" % kbps,
           "-maxrate", "%dk" % kbps, "-bufsize", "%dk" % (kbps * 2),
           "-g", gop, "-keyint_min", gop, "-c:a", "aac", "-b:a", "128k", "-ar", "44100",
           "-f", "flv", cfg["rtmp"]]
    # Never log the command: it contains the private YouTube stream key.
    stream_proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                   stdin=subprocess.DEVNULL, text=True, bufsize=1)
    stream_cfg = cfg
    stream_status = "starting"
    stream_error = None
    stream_started = int(time.time())
    def read_progress(proc):
        global stream_status, stream_fps, stream_speed, stream_error
        try:
            for line in proc.stdout:
                k, _, v = line.strip().partition("=")
                if k == "fps":
                    try: stream_fps = float(v)
                    except ValueError: pass
                elif k == "speed":
                    stream_speed = v
                elif k == "progress" and v in ("continue", "end"):
                    if v == "continue":
                        stream_status = "live"
        except Exception:
            pass
        if proc.poll() not in (None, 0) and stream_status != "stopped":
            try:
                err = proc.stderr.read()[-500:]
            except Exception:
                err = "FFmpeg exited"
            stream_error = redact(err).strip() or "FFmpeg exited"
            stream_status = "error"
    threading.Thread(target=read_progress, args=(stream_proc,), daemon=True).start()


def report():
    if not stream_cfg:
        return []
    return [{"id": int(stream_cfg["id"]), "status": stream_status,
             "fps": stream_fps if stream_status == "live" else None,
             "speed": stream_speed if stream_status == "live" else None,
             "started_at": stream_started if stream_status == "live" else None,
             "error": stream_error if stream_status == "error" else None}]


def shutdown(*_):
    global running
    running = False
    stop_stream()


def main():
    global stream_status
    if platform.system() != "Darwin":
        raise SystemExit("This worker is for macOS only.")
    if not shutil.which("ffmpeg"):
        raise SystemExit("FFmpeg is not installed or not in PATH.")
    if not os.path.isfile(CONF_FILE):
        raise SystemExit("Config missing. Create " + CONF_FILE + " with panel and token; see worker/MACOS.md.")
    with open(CONF_FILE, "r") as f:
        conf = json.load(f)
    panel, token = conf["panel"], conf["token"]
    if not panel.startswith("https://") or not token.startswith("fft_"):
        raise SystemExit("Config panel/token is invalid.")
    signal.signal(signal.SIGINT, shutdown)
    signal.signal(signal.SIGTERM, shutdown)
    log("FFTEdit macOS worker %s started; panel %s" % (VERSION, panel))
    while running:
        try:
            data = call_panel(panel, token, report())
            wanted = data.get("run", [])
            if len(wanted) > 1:
                # Avoid accidentally running multiple streams from the same desktop capture.
                raise RuntimeError("This macOS worker currently supports one assigned stream at a time. Assign only one stream.")
            if not wanted:
                if stream_cfg:
                    stop_stream()
            else:
                cfg = wanted[0]
                if stream_cfg and (int(cfg["id"]) != int(stream_cfg["id"]) or int(cfg.get("rev", 0)) != int(stream_cfg.get("rev", 0))):
                    stop_stream()
                if not stream_cfg and cfg.get("rtmp") and cfg.get("page_url"):
                    try:
                        start_stream(cfg)
                    except Exception as ex:
                        stream_status, stream_error = "error", str(ex)[:300]
                        log("[stream] ERROR: %s" % stream_error)
            time.sleep(max(2, min(15, int(data.get("poll_seconds", POLL)))))
        except urllib.error.HTTPError as ex:
            # Do not log response bodies; they could contain server details.
            log("Panel returned HTTP %s. Check server registration/token." % ex.code)
            time.sleep(10)
        except Exception as ex:
            log("Worker loop error: %s" % str(ex)[:250])
            time.sleep(10)
    stop_stream()
    log("Worker stopped.")


if __name__ == "__main__":
    main()
