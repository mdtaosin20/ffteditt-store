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

VERSION = "1.7.0"
CONF_FILE = os.environ.get("FFT_CONF", "/etc/ffteditt-worker.json")
STATE_DIR = os.environ.get("FFT_STATE", "/var/lib/ffteditt-worker")
CHROME = os.environ.get("FFT_CHROME", "google-chrome")
X264_PRESET = os.environ.get("FFT_X264_PRESET", "veryfast")


PULSE_DIR = "/run/fft-pulse"
PULSE_SOCK = PULSE_DIR + "/native"
TTS_DIR = "/opt/ffteditt-worker/tts"
VOICE_MODEL = "en_US-ryan-high"
VOICE_URL = "https://huggingface.co/rhasspy/piper-voices/resolve/v1.0.0/en/en_US/ryan/high/" + VOICE_MODEL
# Voices for the live host (made on the fly). key -> path inside the Piper voices collection
HOST_VOICES = {
    "ryan": "en/en_US/ryan/medium/en_US-ryan-medium",
    "lessac": "en/en_US/lessac/medium/en_US-lessac-medium",
    "amy": "en/en_US/amy/medium/en_US-amy-medium",
    "kristin": "en/en_US/kristin/medium/en_US-kristin-medium",
    "hfc": "en/en_US/hfc_female/medium/en_US-hfc_female-medium",
    "jenny": "en/en_GB/jenny_dioco/medium/en_GB-jenny_dioco-medium",
    "cori": "en/en_GB/cori/medium/en_GB-cori-medium",
    "lj": "en/en_US/ljspeech/high/en_US-ljspeech-high",
    "lessac_hd": "en/en_US/lessac/high/en_US-lessac-high",
    "cori_hd": "en/en_GB/cori/high/en_GB-cori-high",
    "alba": "en/en_GB/alba/medium/en_GB-alba-medium",
    "southern": "en/en_GB/southern_english_female/low/en_GB-southern_english_female-low",
}
HOST_VOICE_BASE = "https://huggingface.co/rhasspy/piper-voices/resolve/main/"


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


# ------------------------------------------------------------ robot host voice (live)
ROBOT_PY = r"""
import json, sys, wave
from piper import PiperVoice
v = PiperVoice.load(sys.argv[1])
print("ready", flush=True)
for line in sys.stdin:
    try:
        j = json.loads(line)
        with wave.open(j["out"], "wb") as w:
            if hasattr(v, "synthesize_wav"):
                try:
                    from piper import SynthesisConfig
                    v.synthesize_wav(j["text"], w, syn_config=SynthesisConfig(length_scale=0.95))
                except ImportError:
                    v.synthesize_wav(j["text"], w)
            else:
                v.synthesize(j["text"], w, length_scale=0.95)
        print("ok", flush=True)
    except Exception as ex:
        print("err " + str(ex).replace("\n", " ")[:200], flush=True)
"""


class RobotVoice:
    """Speaks the robot host's lines. Keeps Piper loaded so each line takes about a second."""

    def __init__(self, voices):
        self.voices = voices          # VoiceMaker: shares the Piper install
        self.lock = threading.Lock()
        self.jobs = []
        self.results = []
        self.wake = threading.Event()
        self.proc = None
        self.proc_voice = None
        self.failed_until = {}       # voice -> time until which it is not retried
        threading.Thread(target=self._loop, daemon=True).start()

    def submit(self, jobs):
        if not jobs:
            return
        with self.lock:
            have = {j["h"] for j in self.jobs}
            for j in jobs:
                if j.get("h") and j["h"] not in have:
                    self.jobs.append(j)
            self.jobs = self.jobs[-6:]
        self.wake.set()

    def take(self):
        with self.lock:
            r, self.results = self.results, []
            return r

    def put_back(self, results):
        with self.lock:
            self.results = results + self.results

    def _start(self, voice):
        self._stop_proc()
        py, _say, _model = self.voices._setup()
        mdir = TTS_DIR + "/models"
        path = HOST_VOICES[voice]
        name = path.rsplit("/", 1)[1]
        for ext in (".onnx", ".onnx.json"):
            dest = mdir + "/" + name + ext
            if not os.path.exists(dest) or os.path.getsize(dest) < 100:
                log("[robot] downloading voice %s%s" % (name, ext))
                tmp = dest + ".part"
                req = urllib.request.Request(HOST_VOICE_BASE + path + ext + "?download=true", headers={"User-Agent": "ffteditt-worker"})
                with urllib.request.urlopen(req, timeout=300) as r, open(tmp, "wb") as f:
                    shutil.copyfileobj(r, f)
                os.replace(tmp, dest)
        script = TTS_DIR + "/robot.py"
        with open(script, "w") as f:
            f.write(ROBOT_PY)
        env = dict(os.environ, OMP_NUM_THREADS="1")
        # low priority: the live stream always comes first
        self.proc = subprocess.Popen(["nice", "-n", "12", py, script, mdir + "/" + name + ".onnx"],
                                     stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                     text=True, bufsize=1, env=env)
        if self.proc.stdout.readline().strip() != "ready":
            raise RuntimeError("voice did not start")
        self.proc_voice = voice
        log("[robot] voice %s ready" % voice)

    def _stop_proc(self):
        try:
            if self.proc:
                self.proc.kill()
        except Exception:
            pass
        self.proc = None
        self.proc_voice = None

    def _make(self, j):
        tmp = tempfile.mkdtemp(prefix="fftrobot-")
        try:
            wav, mp3 = tmp + "/a.wav", tmp + "/a.mp3"
            self.proc.stdin.write(json.dumps({"text": j["text"], "out": wav}) + "\n")
            self.proc.stdin.flush()
            ans = self.proc.stdout.readline().strip()
            if ans != "ok":
                raise RuntimeError(ans or "robot voice stopped")
            c = run(["nice", "-n", "12", "ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-i", wav,
                     "-af", "loudnorm=I=-15:TP=-1.5:LRA=9", "-ar", "24000", "-ac", "1", "-b:a", "64k", mp3], timeout=60)
            if c.returncode != 0 or not os.path.exists(mp3):
                raise RuntimeError("mp3 failed")
            with open(mp3, "rb") as f:
                return {"h": j["h"], "mp3": base64.b64encode(f.read()).decode()}
        finally:
            shutil.rmtree(tmp, ignore_errors=True)

    def _loop(self):
        while True:
            self.wake.wait(5)
            self.wake.clear()
            while True:
                with self.lock:
                    j = self.jobs.pop() if self.jobs else None   # newest first
                if not j:
                    break
                voice = j.get("voice") if j.get("voice") in HOST_VOICES else "ryan"
                if time.time() < self.failed_until.get(voice, 0):
                    continue
                try:
                    if not self.proc or self.proc.poll() is not None or self.proc_voice != voice:
                        self._start(voice)
                    res = self._make(j)
                    with self.lock:
                        self.results.append(res)
                except Exception as ex:
                    log("[robot] voice %s problem: %s" % (voice, ex))
                    self._stop_proc()
                    self.failed_until[voice] = time.time() + 60


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
                    # No graphics card on a VPS: plain software drawing is many times faster than
                    # emulating a GPU (SwiftShader). The games switch to their light 2D drawing.
                    "--disable-gpu", "--disable-gpu-compositing",
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


# ---------------------------------------------------------------- live chat reader
YT_UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
         "(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36")


def yt_request(url, body=None, timeout=15):
    headers = {"User-Agent": YT_UA, "Accept-Language": "en-US,en;q=0.9", "Cookie": "CONSENT=YES+cb; SOCS=CAI"}
    data = None
    if body is not None:
        data = json.dumps(body).encode()
        headers["Content-Type"] = "application/json"
    req = urllib.request.Request(url, data=data, headers=headers, method="POST" if body is not None else "GET")
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read().decode("utf-8", "replace")


def find_key(obj, key, depth=0):
    """First value of `key` anywhere inside nested dicts/lists."""
    if depth > 40:
        return None
    if isinstance(obj, dict):
        if key in obj:
            return obj[key]
        for v in obj.values():
            r = find_key(v, key, depth + 1)
            if r is not None:
                return r
    elif isinstance(obj, list):
        for v in obj:
            r = find_key(v, key, depth + 1)
            if r is not None:
                return r
    return None


def page_json(html, name):
    m = re.search(r'(?:window\["%s"\]|var %s|%s)\s*=\s*(\{.+?\})\s*;\s*(?:</script>|var |window\[|\n)' % (name, name, name), html, re.S)
    if not m:
        return None
    try:
        return json.loads(m.group(1))
    except ValueError:
        return None


def runs_text(msg):
    out = []
    for r in (msg or {}).get("runs", []):
        if "text" in r:
            out.append(r["text"])
        elif "emoji" in r:
            e = r["emoji"]
            if not e.get("isCustomEmoji") and e.get("emojiId") and len(e["emojiId"]) <= 12:
                out.append(e["emojiId"])
    return "".join(out) or (msg or {}).get("simpleText", "")


def chat_item(item):
    """One chat action from YouTube -> small dict for the panel (or None)."""
    for key, kind in (("liveChatTextMessageRenderer", "chat"), ("liveChatPaidMessageRenderer", "super"),
                      ("liveChatPaidStickerRenderer", "super"), ("liveChatMembershipItemRenderer", "member")):
        r = item.get(key)
        if not r:
            continue
        author = (r.get("authorName") or {}).get("simpleText", "")
        text = runs_text(r.get("message"))
        if kind == "member" and not text:
            text = runs_text(r.get("headerSubtext"))
        amount = (r.get("purchaseAmountText") or {}).get("simpleText", "")
        try:
            ts = int(r.get("timestampUsec", "0")) / 1e6
        except ValueError:
            ts = 0
        return {"id": r.get("id", ""), "k": kind, "a": author, "t": text[:200], "amt": amount, "ts": ts}
    return None


class ChatReader:
    """Reads one live chat with YouTube's own web-page requests (no API key, no daily limit)."""

    def __init__(self, sid):
        self.sid = sid
        self.lock = threading.Lock()
        self.items = []
        self.status = "Starting..."
        self.target = None          # {"video": "...", "channel": "..."}
        self.wake = threading.Event()
        self.stopped = False
        self.count = 0
        threading.Thread(target=self._loop, daemon=True).start()

    def set_target(self, target):
        if target != self.target:
            self.target = target
            self.wake.set()

    def stop(self):
        self.stopped = True
        self.wake.set()

    def take(self):
        with self.lock:
            items, self.items = self.items, []
            return {"id": self.sid, "items": items, "status": self.status}

    def put_back(self, batch):
        with self.lock:
            self.items = batch["items"] + self.items
            self.items = self.items[-300:]

    def _set(self, status):
        self.status = status

    def _video_of_channel(self, url):
        html = yt_request(url.rstrip("/") + "/live")
        m = re.search(r'<link rel="canonical" href="https://www\.youtube\.com/watch\?v=([\w-]{11})"', html)
        return m.group(1) if m else ""

    def _open(self, video):
        html = yt_request("https://www.youtube.com/live_chat?is_popout=1&v=" + video)
        key = re.search(r'"INNERTUBE_API_KEY"\s*:\s*"([^"]+)"', html)
        ver = re.search(r'"INNERTUBE_CLIENT_VERSION"\s*:\s*"([^"]+)"', html) or re.search(r'"clientVersion"\s*:\s*"([\d.]+)"', html)
        data = page_json(html, "ytInitialData")
        if not data:
            raise RuntimeError("chat page not readable")
        renderer = find_key(data, "liveChatRenderer") or {}
        if not renderer:
            raise RuntimeError("chat is off or the video is not live")
        cont = None
        # "Live chat" (all messages) instead of "Top chat"
        sub = find_key(renderer.get("header", {}), "subMenuItems") or []
        if len(sub) > 1:
            cont = find_key(sub[1], "continuation")
        if not cont:
            cont = find_key(renderer.get("continuations", []), "continuation")
        if not cont:
            raise RuntimeError("no chat continuation")
        while isinstance(cont, dict):
            cont = find_key(cont, "continuation")
        if not isinstance(cont, str) or not cont:
            raise RuntimeError("no chat continuation")
        return (key.group(1) if key else ""), (ver.group(1) if ver else "2.20240101.00.00"), cont

    def _loop(self):
        while not self.stopped:
            t = self.target
            if not t:
                self._set("Waiting for the live video")
                self.wake.wait(30)
                self.wake.clear()
                continue
            try:
                video = t.get("video") or ""
                if not video and t.get("channel"):
                    video = self._video_of_channel(t["channel"])
                if not video:
                    raise RuntimeError("live video not found yet")
                key, ver, cont = self._open(video)
                started = time.time() - 5     # only messages from now on (the first answer repeats old ones)
                self._set("Reading chat of %s" % video)
                log("[stream %d] reading live chat of %s" % (self.sid, video))
                fails = 0
                while not self.stopped and self.target == t:
                    try:
                        raw = yt_request("https://www.youtube.com/youtubei/v1/live_chat/get_live_chat?prettyPrint=false" + ("&key=" + key if key else ""),
                                         {"context": {"client": {"clientName": "WEB", "clientVersion": ver, "hl": "en", "gl": "US"}},
                                          "continuation": cont})
                        fails = 0
                    except (urllib.error.URLError, socket.timeout, OSError) as ex:
                        fails += 1
                        if fails >= 5:
                            raise RuntimeError("chat requests failing (%s)" % ex)
                        self.wake.wait(3)
                        continue
                    j = json.loads(raw)
                    lcc = (j.get("continuationContents") or {}).get("liveChatContinuation")
                    if not lcc:
                        raise RuntimeError("live chat ended")
                    new = []
                    for a in lcc.get("actions", []) or []:
                        it = (a.get("addChatItemAction") or {}).get("item")
                        if not it:
                            continue
                        c = chat_item(it)
                        if c and c["a"] and c["ts"] >= started:
                            new.append(c)
                    if new:
                        self.count += len(new)
                        with self.lock:
                            self.items.extend(new)
                            self.items = self.items[-300:]
                    self._set("Reading chat (%d new since start)" % self.count)
                    nxt = (lcc.get("continuations") or [{}])[0]
                    info = next((v for v in nxt.values() if isinstance(v, dict) and v.get("continuation")), None)
                    if not info:
                        raise RuntimeError("live chat ended")
                    cont = info["continuation"]
                    wait = int(info.get("timeoutMs", 2000)) / 1000.0
                    self.wake.wait(max(1.5, min(5.0, wait)))
                    self.wake.clear()
            except Exception as ex:
                self._set("Chat not readable: %s - retrying" % str(ex)[:90])
                log("[stream %d] live chat: %s" % (self.sid, ex))
                self.wake.wait(20)
                self.wake.clear()


def call_panel(panel, token, reports, cmd_results=None, voice_results=None, voice_error=None, chat=None, robot=None):
    body = json.dumps({"token": token, "info": machine_info(), "streams": reports,
                       "command_results": cmd_results or [], "voice_results": voice_results or [],
                       "voice_error": voice_error, "chat": chat or [], "robot_results": robot or []}).encode()
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
    readers = {}        # id -> ChatReader
    robot = RobotVoice(voices)

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
        chat = [r.take() for r in readers.values()]
        rres = robot.take()
        try:
            resp = call_panel(panel, token, reports, results, vres, verr, chat, rres)
            robot.submit(resp.get("robot_jobs") or [])
            runner.done([r["id"] for r in results])
            voices.submit(resp.get("voice_jobs") or [])
            poll = max(1, min(30, int(resp.get("poll_seconds", 5))))
            wanted = {int(c["id"]): c for c in resp.get("run", [])}
            for c in resp.get("commands", []):
                runner.submit(int(c["id"]), str(c["cmd"]))
        except urllib.error.HTTPError as ex:
            robot.put_back(rres)
            runner.put_back(results)
            voices.put_back(vres)
            for b in chat:
                if b["id"] in readers:
                    readers[b["id"]].put_back(b)
            log("Panel said HTTP %s - %s" % (ex.code, "token wrong or server removed?" if ex.code == 401 else ""))
            wanted = None
        except Exception as ex:
            robot.put_back(rres)
            runner.put_back(results)
            voices.put_back(vres)
            for b in chat:
                if b["id"] in readers:
                    readers[b["id"]].put_back(b)
            log("Panel not reachable (%s) - keeping current streams running" % ex)
            wanted = None

        if wanted is not None:
            # live chat readers: one per live stream that has a chat target
            for sid in list(readers):
                if sid not in wanted or not wanted[sid].get("chat"):
                    readers.pop(sid).stop()
            for sid, cfg in wanted.items():
                if cfg.get("chat"):
                    if sid not in readers:
                        readers[sid] = ChatReader(sid)
                    readers[sid].set_target(cfg["chat"])

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
