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

import base64
import json
import shutil
import tempfile
import wave
import os
import re
import signal
import socket
import subprocess
import threading
import time
import urllib.error
import urllib.request

VERSION = "1.4.0"
CONF_FILE = os.environ.get("FFT_CONF", "/etc/ffteditt-worker.json")
STATE_DIR = os.environ.get("FFT_STATE", "/var/lib/ffteditt-worker")
CHROME = os.environ.get("FFT_CHROME", "google-chrome")
X264_PRESET = os.environ.get("FFT_X264_PRESET", "veryfast")


PULSE_DIR = "/run/fft-pulse"
PULSE_SOCK = PULSE_DIR + "/native"
TTS_DIR = "/opt/ffteditt-worker/tts"
VOICE_MODEL = "en_US-ryan-high"
VOICE_URL = "https://huggingface.co/rhasspy/piper-voices/resolve/v1.0.0/en/en_US/ryan/high/" + VOICE_MODEL


def log(msg):
    print(time.strftime("%Y-%m-%d %H:%M:%S"), msg, flush=True)


def redact(text):
    # Never print stream keys: hide anything after rtmp(s)://host/app/
    return re.sub(r"(rtmps?://[^\s/]+/[^\s/]+/)\S+", r"\1****", text)


def run(cmd, timeout=120, env=None, input=None):
    return subprocess.run(cmd, capture_output=True, text=True, timeout=timeout, env=env, input=input)


def apt_install(*pkgs):
    env = dict(os.environ, DEBIAN_FRONTEND="noninteractive")
    r = run(["apt-get", "install", "-y", "-q"] + list(pkgs), timeout=900, env=env)
    if r.returncode != 0:
        run(["apt-get", "update", "-q"], timeout=600, env=env)
        r = run(["apt-get", "install", "-y", "-q"] + list(pkgs), timeout=900, env=env)
    return r.returncode == 0


# ---------------------------------------------------------------- stream sound
# One private PulseAudio sound server; every stream gets its own silent "speaker"
# (null sink). Chrome plays into it and FFmpeg records it.
_pulse_lock = threading.Lock()
_pulse_proc = None


def pulse_env():
    return dict(os.environ, PULSE_SERVER="unix:" + PULSE_SOCK, HOME=PULSE_DIR, XDG_RUNTIME_DIR=PULSE_DIR)


def ensure_pulse():
    global _pulse_proc
    with _pulse_lock:
        try:
            if not shutil.which("pulseaudio") or not shutil.which("pactl"):
                log("[sound] installing PulseAudio")
                if not apt_install("pulseaudio", "pulseaudio-utils"):
                    return False
            env = pulse_env()
            if run(["pactl", "info"], timeout=10, env=env).returncode == 0:
                return True
            os.makedirs(PULSE_DIR, mode=0o700, exist_ok=True)
            _pulse_proc = subprocess.Popen(
                ["pulseaudio", "--daemonize=no", "--exit-idle-time=-1", "--disallow-exit", "-n",
                 "--use-pid-file=no", "--log-target=stderr",
                 "--load=module-native-protocol-unix socket=%s auth-anonymous=1" % PULSE_SOCK,
                 "--load=module-null-sink sink_name=fft_idle"],
                env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True,
            )
            for _ in range(40):
                if run(["pactl", "info"], timeout=10, env=env).returncode == 0:
                    log("[sound] PulseAudio ready")
                    return True
                time.sleep(0.25)
            log("[sound] PulseAudio did not start - streams will be silent")
            return False
        except Exception as ex:
            log("[sound] not available: %s" % ex)
            return False


# ------------------------------------------------------------ commentator voice
SAY_PY = r"""
import json, sys, wave
from piper import PiperVoice
model, outdir = sys.argv[1], sys.argv[2]
jobs = json.load(sys.stdin)
v = PiperVoice.load(model)
for j in jobs:
    path = outdir + "/" + j["id"] + ".wav"
    with wave.open(path, "wb") as w:
        if hasattr(v, "synthesize_wav"):
            try:
                from piper import SynthesisConfig
                v.synthesize_wav(j["text"], w, syn_config=SynthesisConfig(length_scale=0.9))
            except ImportError:
                v.synthesize_wav(j["text"], w)
        else:
            v.synthesize(j["text"], w, length_scale=0.9)
    print(j["id"], flush=True)
"""


class VoiceMaker:
    """Makes the English commentator lines with Piper (free, offline AI voice)."""

    def __init__(self):
        self.lock = threading.Lock()
        self.busy = False
        self.results = []
        self.error = None
        self.error_sent = None
        self.pause_until = 0

    def submit(self, jobs):
        if not jobs or self.busy or time.time() < self.pause_until:
            return
        self.busy = True
        threading.Thread(target=self._work, args=(jobs,), daemon=True).start()

    def take(self):
        with self.lock:
            r, self.results = self.results, []
        err = None
        if self.error and self.error != self.error_sent:
            err = self.error_sent = self.error
        return r, err

    def put_back(self, results):
        with self.lock:
            self.results = results + self.results

    def _setup(self):
        py = TTS_DIR + "/bin/python"
        if not os.path.exists(py):
            log("[voice] creating the voice program folder")
            if run(["python3", "-m", "venv", TTS_DIR], timeout=300).returncode != 0:
                apt_install("python3-venv")
                shutil.rmtree(TTS_DIR, ignore_errors=True)
                r = run(["python3", "-m", "venv", TTS_DIR], timeout=300)
                if r.returncode != 0:
                    raise RuntimeError("could not create Python environment: " + (r.stderr or "")[-200:])
        if run([py, "-c", "import piper"], timeout=60).returncode != 0:
            log("[voice] installing Piper voice (one time, a few minutes)")
            run([py, "-m", "pip", "install", "-q", "--upgrade", "pip"], timeout=600)
            r = run([py, "-m", "pip", "install", "-q", "piper-tts"], timeout=1200)
            if r.returncode != 0:
                raise RuntimeError("could not install Piper: " + (r.stderr or r.stdout or "")[-200:])
        mdir = TTS_DIR + "/models"
        os.makedirs(mdir, exist_ok=True)
        model = mdir + "/" + VOICE_MODEL + ".onnx"
        for ext in (".onnx", ".onnx.json"):
            dest = mdir + "/" + VOICE_MODEL + ext
            if not os.path.exists(dest) or os.path.getsize(dest) < 100:
                log("[voice] downloading voice model %s" % ext)
                tmp = dest + ".part"
                req = urllib.request.Request(VOICE_URL + ext + "?download=true", headers={"User-Agent": "ffteditt-worker"})
                with urllib.request.urlopen(req, timeout=300) as r, open(tmp, "wb") as f:
                    shutil.copyfileobj(r, f)
                os.replace(tmp, dest)
        say = TTS_DIR + "/say.py"
        with open(say, "w") as f:
            f.write(SAY_PY)
        return py, say, model

    def _work(self, jobs):
        try:
            py, say, model = self._setup()
            out = tempfile.mkdtemp(prefix="fftvoice-")
            try:
                r = run([py, say, model, out], timeout=900, input=json.dumps([{"id": j["id"], "text": j["text"]} for j in jobs]))
                if r.returncode != 0:
                    raise RuntimeError("voice program failed: " + (r.stderr or "")[-200:])
                made = []
                for j in jobs:
                    wav = os.path.join(out, j["id"] + ".wav")
                    if not os.path.exists(wav):
                        continue
                    with wave.open(wav) as w:
                        rate = w.getframerate()
                    mp3 = wav[:-4] + ".mp3"
                    # a little higher and faster = more excited commentator
                    fx = "asetrate=%d,aresample=44100,atempo=1.03,loudnorm=I=-15:TP=-1.5:LRA=9" % int(rate * 1.05)
                    c = run(["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-i", wav, "-af", fx,
                             "-ac", "1", "-b:a", "96k", mp3], timeout=120)
                    if c.returncode == 0 and os.path.exists(mp3):
                        with open(mp3, "rb") as f:
                            made.append({"id": j["id"], "hash": j["hash"], "mp3": base64.b64encode(f.read()).decode()})
                with self.lock:
                    self.results.extend(made)
                self.error = None
                log("[voice] made %d line(s)" % len(made))
            finally:
                shutil.rmtree(out, ignore_errors=True)
        except Exception as ex:
            self.error = str(ex)[:280]
            self.pause_until = time.time() + 300
            log("[voice] problem: %s" % self.error)
        finally:
            self.busy = False


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
        self.sink = None
        self.sink_module = None

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

            # 2) sound: a private "speaker" for this stream
            chrome_env = dict(env)
            if ensure_pulse():
                penv = pulse_env()
                sink = "fft_s%d" % self.id
                r = run(["pactl", "load-module", "module-null-sink", "sink_name=" + sink, "rate=44100", "channels=2"], timeout=15, env=penv)
                if r.returncode == 0:
                    self.sink, self.sink_module = sink, r.stdout.strip()
                    chrome_env.update(PULSE_SERVER=penv["PULSE_SERVER"], PULSE_SINK=sink)
            if not self.sink:
                log("[stream %d] no sound (silent stream)" % self.id)

            # 3) Chrome showing the game page
            profile = os.path.join(STATE_DIR, "chrome-%d" % self.id)
            os.makedirs(profile, exist_ok=True)
            self._spawn(
                [
                    CHROME, "--no-sandbox", "--kiosk", "--no-first-run", "--no-default-browser-check",
                    "--disable-infobars", "--disable-dev-shm-usage", "--disable-features=Translate",
                    "--disable-session-crashed-bubble", "--hide-scrollbars",
                    "--autoplay-policy=no-user-gesture-required", "--force-device-scale-factor=1",
                    # 3D (WebGL) without a graphics card: draw with the CPU
                    "--use-angle=swiftshader", "--enable-unsafe-swiftshader", "--ignore-gpu-blocklist",
                    "--disable-background-timer-throttling", "--disable-renderer-backgrounding",
                    "--disable-backgrounding-occluded-windows",
                    "--user-data-dir=" + profile, "--window-position=0,0", "--window-size=%d,%d" % (w, h),
                    c["page_url"],
                ] + ([] if self.sink else ["--mute-audio"]),
                env=chrome_env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            )
            if not self._sleep(8):
                return

            # 4) FFmpeg → YouTube
            gop = str(fps * 2)
            if self.sink:
                audio_in = ["-thread_queue_size", "1024", "-f", "pulse", "-sample_rate", "44100", "-channels", "2",
                            "-i", self.sink + ".monitor"]
            else:
                audio_in = ["-f", "lavfi", "-i", "anullsrc=r=44100:cl=stereo"]
            cmd = [
                "ffmpeg", "-hide_banner", "-loglevel", "error", "-nostats", "-progress", "pipe:1",
                "-thread_queue_size", "512",
                "-f", "x11grab", "-draw_mouse", "0", "-video_size", "%dx%d" % (w, h),
                "-framerate", str(fps), "-i", self.display + ".0",
            ] + audio_in + [
                "-c:v", "libx264", "-preset", X264_PRESET, "-tune", "zerolatency", "-pix_fmt", "yuv420p",
                "-b:v", "%dk" % kbps, "-maxrate", "%dk" % kbps, "-bufsize", "%dk" % (kbps * 2),
                "-g", gop, "-keyint_min", gop, "-sc_threshold", "0",
                "-af", "aresample=async=1000", "-c:a", "aac", "-b:a", "128k", "-ar", "44100",
                "-f", "flv", c["rtmp"],
            ]
            self.ffmpeg = self._spawn(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                      stdin=subprocess.DEVNULL, text=True, bufsize=1,
                                      env=pulse_env() if self.sink else None)
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
            if self.sink_module:
                try:
                    run(["pactl", "unload-module", self.sink_module], timeout=10, env=pulse_env())
                except Exception:
                    pass
                self.sink = self.sink_module = None
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


class Stats:
    """CPU, disk, network and top programs for the dashboard (all read from /proc, no extra packages)."""

    def __init__(self):
        self.cpu_prev = None
        self.net_prev = None
        self.proc_prev = {}
        self.proc_time = None
        self.page = os.sysconf("SC_PAGE_SIZE") if hasattr(os, "sysconf") else 4096
        self.hz = os.sysconf("SC_CLK_TCK") if hasattr(os, "sysconf") else 100

    def cpu(self):
        with open("/proc/stat") as f:
            v = [int(x) for x in f.readline().split()[1:]]
        idle, total = v[3] + (v[4] if len(v) > 4 else 0), sum(v[:8])
        prev, self.cpu_prev = self.cpu_prev, (idle, total)
        if not prev or total <= prev[1]:
            return None
        return round(100.0 * (1 - (idle - prev[0]) / float(total - prev[1])), 1)

    def net(self):
        rx = tx = 0
        with open("/proc/net/dev") as f:
            for line in f.readlines()[2:]:
                name, data = line.split(":", 1)
                if name.strip() == "lo":
                    continue
                d = data.split()
                rx += int(d[0])
                tx += int(d[8])
        now = time.time()
        prev, self.net_prev = self.net_prev, (now, rx, tx)
        up = down = None
        if prev and now > prev[0] and rx >= prev[1] and tx >= prev[2]:
            down = round((rx - prev[1]) * 8 / 1000.0 / (now - prev[0]), 1)
            up = round((tx - prev[2]) * 8 / 1000.0 / (now - prev[0]), 1)
        return rx, tx, up, down

    def procs(self):
        now = time.time()
        cur, mem = {}, {}
        for pid in os.listdir("/proc"):
            if not pid.isdigit():
                continue
            try:
                with open("/proc/%s/stat" % pid) as f:
                    raw = f.read()
                name = raw[raw.index("(") + 1:raw.rindex(")")]
                rest = raw[raw.rindex(")") + 2:].split()
                ticks = int(rest[11]) + int(rest[12])
                rss = int(rest[21]) * self.page
            except (OSError, ValueError, IndexError):
                continue
            group = self.group(name)
            cur[pid] = (group, ticks)
            mem[group] = mem.get(group, 0) + rss
        usage = {}
        if self.proc_time:
            dt = now - self.proc_time
            for pid, (group, ticks) in cur.items():
                old = self.proc_prev.get(pid)
                if old and old[0] == group and ticks >= old[1]:
                    usage[group] = usage.get(group, 0) + (ticks - old[1]) / float(self.hz) / dt * 100
        self.proc_prev, self.proc_time = cur, now
        ncpu = os.cpu_count() or 1
        out = [{"name": g, "cpu": round(usage.get(g, 0) / ncpu, 1), "mem_mb": mem[g] // (1024 * 1024)} for g in mem]
        out.sort(key=lambda p: (p["cpu"], p["mem_mb"]), reverse=True)
        return out[:6]

    @staticmethod
    def group(name):
        n = name.lower()
        for key, label in (("chrome", "chrome (game)"), ("ffmpeg", "ffmpeg (stream)"), ("xvfb", "Xvfb (screen)"),
                           ("pulseaudio", "pulseaudio (sound)"), ("python", "worker"), ("piper", "voice maker")):
            if key in n:
                return label
        return "system"

    def collect(self):
        info = {}
        try:
            info["cpu_pct"] = self.cpu()
        except Exception:
            pass
        try:
            du = shutil.disk_usage("/")
            info["disk_total_gb"] = round(du.total / 1024.0 ** 3, 1)
            info["disk_used_gb"] = round(du.used / 1024.0 ** 3, 1)
        except Exception:
            pass
        try:
            info["rx_bytes"], info["tx_bytes"], info["up_kbps"], info["down_kbps"] = self.net()
        except Exception:
            pass
        try:
            with open("/proc/uptime") as f:
                info["uptime_s"] = int(float(f.read().split()[0]))
        except Exception:
            pass
        try:
            temps = []
            for z in os.listdir("/sys/class/thermal"):
                if z.startswith("thermal_zone"):
                    with open("/sys/class/thermal/%s/temp" % z) as f:
                        temps.append(int(f.read().strip()) / 1000.0)
            if temps:
                info["temp_c"] = round(max(temps), 1)
        except Exception:
            pass
        try:
            info["procs"] = self.procs()
        except Exception:
            pass
        return info


STATS = Stats()


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
    info.update(STATS.collect())
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


def call_panel(panel, token, reports, cmd_results=None, voice_results=None, voice_error=None):
    body = json.dumps({"token": token, "info": machine_info(), "streams": reports,
                       "command_results": cmd_results or [], "voice_results": voice_results or [],
                       "voice_error": voice_error}).encode()
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
    voices = VoiceMaker()

    def shutdown(*_):
        running["on"] = False
        wake.set()

    signal.signal(signal.SIGTERM, shutdown)
    signal.signal(signal.SIGINT, shutdown)

    while running["on"]:
        wake.clear()
        reports = [s.report() for s in streams.values()]
        results = runner.take_results()
        vres, verr = voices.take()
        try:
            resp = call_panel(panel, token, reports, results, vres, verr)
            runner.done([r["id"] for r in results])
            voices.submit(resp.get("voice_jobs") or [])
            poll = max(1, min(30, int(resp.get("poll_seconds", 5))))
            wanted = {int(c["id"]): c for c in resp.get("run", [])}
            for c in resp.get("commands", []):
                runner.submit(int(c["id"]), str(c["cmd"]))
        except urllib.error.HTTPError as ex:
            runner.put_back(results)
            voices.put_back(vres)
            log("Panel said HTTP %s - %s" % (ex.code, "token wrong or server removed?" if ex.code == 401 else ""))
            wanted = None
        except Exception as ex:
            runner.put_back(results)
            voices.put_back(vres)
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
