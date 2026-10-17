#!/usr/bin/env python3
"""
FFTEdit stream worker.

Runs on a VPS as a systemd service. Every few seconds it asks the admin panel
(https://ffteditt.store/api/worker.php) which streams should be live, starts or
stops them, and reports back their status.

Each stream = its own invisible screen (Xvfb) + Google Chrome showing the game
page + FFmpeg sending that screen to YouTube.

Config: /etc/ffteditt-worker.json  {"panel": "https://ffteditt.store", "token": "fft_..."}
Logs:   journalctl -u ffteditt-worker -f
"""

import json
import os
import re
import signal
import socket
import subprocess
import threading
import time
import urllib.error
import urllib.request

VERSION = "1.2.0"
CONF_FILE = os.environ.get("FFT_CONF", "/etc/ffteditt-worker.json")
STATE_DIR = os.environ.get("FFT_STATE", "/var/lib/ffteditt-worker")
CHROME = os.environ.get("FFT_CHROME", "google-chrome")
X264_PRESET = os.environ.get("FFT_X264_PRESET", "veryfast")


def log(msg):
    print(time.strftime("%Y-%m-%d %H:%M:%S"), msg, flush=True)


def redact(text):
    # Never print stream keys: hide anything after rtmp(s)://host/app/
    return re.sub(r"(rtmps?://[^\s/]+/[^\s/]+/)\S+", r"\1****", text)


class Stream:
    """One live stream: Xvfb + Chrome + FFmpeg."""

    def __init__(self, cfg):
        self.cfg = cfg
        self.id = int(cfg["id"])
        self.rev = int(cfg["rev"])
        self.display = ":%d" % (100 + self.id % 800)
        self.procs = []
        self.ffmpeg = None
        self.status = "starting"
        self.fps = None
        self.speed = None
        self.started_at = None
        self.error = None
        self.stopping = False
        self.thread = None
        self.last_err_line = ""

    # ---- lifecycle -------------------------------------------------------
    def start(self):
        self.thread = threading.Thread(target=self._run, name="stream-%d" % self.id, daemon=True)
        self.thread.start()

    def finished(self):
        return self.thread is not None and not self.thread.is_alive()

    def stop(self):
        self.stopping = True
        self._kill_all()
        if self.thread:
            self.thread.join(timeout=15)
        self.status = "stopped"

    # ---- internals -------------------------------------------------------
    def _spawn(self, cmd, **kw):
        p = subprocess.Popen(cmd, start_new_session=True, **kw)
        self.procs.append(p)
        return p

    def _kill_all(self):
        for p in reversed(self.procs):
            if p.poll() is None:
                try:
                    os.killpg(p.pid, signal.SIGTERM)
                except (ProcessLookupError, PermissionError):
                    pass
        deadline = time.time() + 8
        for p in self.procs:
            try:
                p.wait(timeout=max(0.1, deadline - time.time()))
            except subprocess.TimeoutExpired:
                try:
                    os.killpg(p.pid, signal.SIGKILL)
                except (ProcessLookupError, PermissionError):
                    pass

    def _sleep(self, seconds):
        end = time.time() + seconds
        while time.time() < end:
            if self.stopping:
                return False
            time.sleep(0.25)
        return True

    def _fail(self, msg):
        if not self.stopping:
            self.status = "error"
            self.error = msg
            log("[stream %d] ERROR: %s" % (self.id, msg))

    def _run(self):
        c = self.cfg
        w, h, fps, kbps = int(c["width"]), int(c["height"]), int(c["fps"]), int(c["kbps"])
        disp_num = self.display[1:]
        env = dict(os.environ, DISPLAY=self.display)
        log("[stream %d] starting '%s' %dx%d@%d" % (self.id, c.get("name", ""), w, h, fps))

        try:
            # 1) invisible screen
            lock = "/tmp/.X%s-lock" % disp_num
            if os.path.exists(lock):
                try:
                    os.remove(lock)
                except OSError:
                    pass
            xvfb = self._spawn(
                ["Xvfb", self.display, "-screen", "0", "%dx%dx24" % (w, h), "-nolisten", "tcp", "-nocursor"],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            )
            sock = "/tmp/.X11-unix/X%s" % disp_num
            for _ in range(40):
                if os.path.exists(sock) or xvfb.poll() is not None:
                    break
                time.sleep(0.25)
            if xvfb.poll() is not None or not os.path.exists(sock):
                return self._fail("Could not start the virtual screen (Xvfb)")

            # 2) Chrome showing the game page
            profile = os.path.join(STATE_DIR, "chrome-%d" % self.id)
            os.makedirs(profile, exist_ok=True)
            self._spawn(
                [
                    CHROME, "--no-sandbox", "--kiosk", "--no-first-run", "--no-default-browser-check",
                    "--disable-infobars", "--disable-dev-shm-usage", "--disable-features=Translate",
                    "--disable-session-crashed-bubble", "--hide-scrollbars", "--mute-audio",
                    "--autoplay-policy=no-user-gesture-required", "--force-device-scale-factor=1",
                    # 3D (WebGL) without a graphics card: draw with the CPU
                    "--use-angle=swiftshader", "--enable-unsafe-swiftshader", "--ignore-gpu-blocklist",
                    "--disable-background-timer-throttling", "--disable-renderer-backgrounding",
                    "--disable-backgrounding-occluded-windows",
                    "--user-data-dir=" + profile, "--window-position=0,0", "--window-size=%d,%d" % (w, h),
                    c["page_url"],
                ],
                env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            )
            if not self._sleep(8):
                return

            # 3) FFmpeg → YouTube
            gop = str(fps * 2)
            cmd = [
                "ffmpeg", "-hide_banner", "-loglevel", "error", "-nostats", "-progress", "pipe:1",
                "-thread_queue_size", "512",
                "-f", "x11grab", "-draw_mouse", "0", "-video_size", "%dx%d" % (w, h),
                "-framerate", str(fps), "-i", self.display + ".0",
                "-f", "lavfi", "-i", "anullsrc=r=44100:cl=stereo",
                "-c:v", "libx264", "-preset", X264_PRESET, "-tune", "zerolatency", "-pix_fmt", "yuv420p",
                "-b:v", "%dk" % kbps, "-maxrate", "%dk" % kbps, "-bufsize", "%dk" % (kbps * 2),
                "-g", gop, "-keyint_min", gop, "-sc_threshold", "0",
                "-c:a", "aac", "-b:a", "128k", "-ar", "44100",
                "-f", "flv", c["rtmp"],
            ]
            self.ffmpeg = self._spawn(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                      stdin=subprocess.DEVNULL, text=True, bufsize=1)
            threading.Thread(target=self._read_stderr, daemon=True).start()

            frames = 0
            for line in self.ffmpeg.stdout:
                key, _, val = line.strip().partition("=")
                if key == "frame":
                    try:
                        frames = int(val)
                    except ValueError:
                        pass
                elif key == "fps":
                    try:
                        self.fps = float(val)
                    except ValueError:
                        pass
                elif key == "speed":
                    self.speed = val.strip() or None
                elif key == "progress":
                    if frames > 0 and self.status != "live":
                        self.status = "live"
                        self.error = None
                        self.started_at = int(time.time())
                        log("[stream %d] LIVE" % self.id)
                    if val == "end":
                        break

            code = self.ffmpeg.wait()
            if not self.stopping:
                msg = self.last_err_line or ("FFmpeg stopped (exit code %s)" % code)
                if "Input/output error" in msg or "Connection refused" in msg:
                    msg += " - check the stream key and that YouTube Live is enabled"
                self._fail(msg)
        except Exception as ex:  # keep the worker alive whatever happens
            self._fail("Worker error: %s" % ex)
        finally:
            self.fps = None
            self.speed = None
            self._kill_all()
            if self.stopping:
                self.status = "stopped"

    def _read_stderr(self):
        p = self.ffmpeg
        for line in p.stderr:
            line = redact(line.strip())
            if line:
                self.last_err_line = line[:280]
                log("[stream %d] ffmpeg: %s" % (self.id, line[:280]))

    def report(self):
        return {
            "id": self.id,
            "status": self.status,
            "fps": self.fps if self.status == "live" else None,
            "speed": self.speed if self.status == "live" else None,
            "started_at": self.started_at if self.status == "live" else None,
            "error": self.error if self.status == "error" else None,
        }


def machine_info():
    info = {
        "hostname": socket.gethostname(),
        "cpus": os.cpu_count() or 0,
        "load1": "%.2f" % os.getloadavg()[0],
        "agent_version": VERSION,
    }
    try:
        mem = {}
        with open("/proc/meminfo") as f:
            for line in f:
                k, v = line.split(":", 1)
                mem[k] = int(v.split()[0])
        info["mem_total_mb"] = mem["MemTotal"] // 1024
        info["mem_used_mb"] = (mem["MemTotal"] - mem.get("MemAvailable", 0)) // 1024
    except Exception:
        pass
    return info


CMD_TIMEOUT = 60
CMD_MAX_OUTPUT = 64 * 1024


class CommandRunner:
    """Runs terminal commands sent from the admin panel, one thread each."""

    def __init__(self, wake):
        self.wake = wake            # threading.Event: send results to the panel right away
        self.lock = threading.Lock()
        self.active = set()         # command ids running now
        self.results = []           # finished, not yet sent

    def submit(self, cmd_id, text):
        with self.lock:
            if cmd_id in self.active:
                return
            self.active.add(cmd_id)
        threading.Thread(target=self._run, args=(cmd_id, text), daemon=True).start()

    def _run(self, cmd_id, text):
        log("[terminal] running command #%d" % cmd_id)
        start = time.time()
        code = None
        try:
            p = subprocess.Popen(
                ["/bin/bash", "-lc", text],
                stdout=subprocess.PIPE, stderr=subprocess.STDOUT, stdin=subprocess.DEVNULL,
                cwd="/root" if os.path.isdir("/root") else "/",
                env=dict(os.environ, TERM="dumb", COLUMNS="100", LINES="40", SYSTEMD_PAGER="", PAGER="cat"),
                start_new_session=True,
            )
            try:
                out, _ = p.communicate(timeout=CMD_TIMEOUT)
                code = p.returncode
            except subprocess.TimeoutExpired:
                try:
                    os.killpg(p.pid, signal.SIGKILL)
                except (ProcessLookupError, PermissionError):
                    pass
                out, _ = p.communicate()
                out = (out or b"") + ("\n[Stopped: took longer than %d seconds]" % CMD_TIMEOUT).encode()
                code = 124
            text_out = (out or b"").decode("utf-8", "replace")
        except Exception as ex:
            text_out = "Could not run the command: %s" % ex
            code = 127
        if len(text_out) > CMD_MAX_OUTPUT:
            text_out = text_out[:CMD_MAX_OUTPUT] + "\n[Output cut: too long]"
        text_out = redact(text_out)
        with self.lock:
            self.results.append({
                "id": cmd_id, "output": text_out, "exit_code": code,
                "duration_ms": int((time.time() - start) * 1000),
            })
        self.wake.set()

    def take_results(self):
        with self.lock:
            r, self.results = self.results, []
            return r

    def put_back(self, results):
        with self.lock:
            self.results = results + self.results

    def done(self, ids):
        with self.lock:
            for i in ids:
                self.active.discard(i)


def call_panel(panel, token, reports, cmd_results=None):
    body = json.dumps({"token": token, "info": machine_info(), "streams": reports,
                       "command_results": cmd_results or []}).encode()
    req = urllib.request.Request(
        panel.rstrip("/") + "/api/worker.php",
        data=body,
        headers={"Content-Type": "application/json", "User-Agent": "ffteditt-worker/" + VERSION},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=20) as r:
        return json.loads(r.read().decode())


def main():
    with open(CONF_FILE) as f:
        conf = json.load(f)
    panel, token = conf["panel"], conf["token"]
    os.makedirs(STATE_DIR, exist_ok=True)
    log("FFTEdit worker %s started, panel %s" % (VERSION, panel))

    streams = {}        # id -> Stream
    retry_at = {}       # id -> time when an auto-restart may happen
    retry_delay = {}    # id -> current backoff seconds
    poll = 5
    running = {"on": True}
    wake = threading.Event()
    runner = CommandRunner(wake)

    def shutdown(*_):
        running["on"] = False
        wake.set()

    signal.signal(signal.SIGTERM, shutdown)
    signal.signal(signal.SIGINT, shutdown)

    while running["on"]:
        wake.clear()
        reports = [s.report() for s in streams.values()]
        results = runner.take_results()
        try:
            resp = call_panel(panel, token, reports, results)
            runner.done([r["id"] for r in results])
            poll = max(1, min(30, int(resp.get("poll_seconds", 5))))
            wanted = {int(c["id"]): c for c in resp.get("run", [])}
            for c in resp.get("commands", []):
                runner.submit(int(c["id"]), str(c["cmd"]))
        except urllib.error.HTTPError as ex:
            runner.put_back(results)
            log("Panel said HTTP %s - %s" % (ex.code, "token wrong or server removed?" if ex.code == 401 else ""))
            wanted = None
        except Exception as ex:
            runner.put_back(results)
            log("Panel not reachable (%s) - keeping current streams running" % ex)
            wanted = None

        if wanted is not None:
            # stop streams that should not run (or whose settings changed)
            for sid in list(streams):
                s = streams[sid]
                if sid not in wanted or int(wanted[sid]["rev"]) != s.rev:
                    log("[stream %d] stopping (%s)" % (sid, "turned off" if sid not in wanted else "settings changed"))
                    s.stop()
                    del streams[sid]
                    retry_at.pop(sid, None)
                    retry_delay.pop(sid, None)

            # start or auto-restart
            now = time.time()
            for sid, cfg in wanted.items():
                s = streams.get(sid)
                if s is None:
                    streams[sid] = Stream(cfg)
                    streams[sid].start()
                elif s.finished():
                    if not cfg.get("auto_restart"):
                        continue  # stays in "error" until the admin presses Stop/Start
                    if sid not in retry_at:
                        delay = retry_delay.get(sid, 10)
                        retry_at[sid] = now + delay
                        retry_delay[sid] = min(delay * 2, 120)
                        log("[stream %d] will restart in %ds" % (sid, delay))
                    elif now >= retry_at[sid]:
                        del retry_at[sid]
                        old_error = s.error
                        streams[sid] = Stream(cfg)
                        streams[sid].error = old_error
                        streams[sid].start()
                elif s.status == "live" and s.started_at and now - s.started_at > 300:
                    retry_delay.pop(sid, None)  # stable for 5 min: reset backoff

        # sleep until the next check-in, or earlier when a command finishes
        wake.wait(timeout=poll)

    log("Shutting down, stopping all streams")
    for s in streams.values():
        s.stop()


if __name__ == "__main__":
    main()
