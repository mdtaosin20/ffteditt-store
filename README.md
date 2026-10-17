# FFTEdit Store — ffteditt.store

Game website plus an admin panel that controls 24/7 YouTube live streams running on one or more VPS servers.

## How it works

```
Admin panel (ffteditt.store/admin, PHP + SQLite on cPanel)
      ▲   every ~5 s: "what should I run?" / "here is my status"
      │
Worker on each VPS (Python service)
      └─ per stream: Xvfb screen → Chrome shows the game page → FFmpeg → YouTube RTMP
```

The VPS only makes outgoing HTTPS calls, so no ports need to be opened on it.

## Folders

| Folder    | Deployed to                                   | What it is                         |
|-----------|-----------------------------------------------|------------------------------------|
| `public/` | `/home/creaexbh/ffteditt.store` (web root)    | Website, admin pages, worker API   |
| `app/`    | `/home/creaexbh/ffteditt_app` (private)       | Shared PHP code                    |
| `worker/` | `ffteditt.store/worker/`                      | VPS agent + one-line installer     |
| —         | `/home/creaexbh/ffteditt_data` (private)      | SQLite database (never in git)     |

Deployment is handled by `.cpanel.yml` (cPanel → Git Version Control → Update from Remote → Deploy HEAD Commit).

## GameStream Admin Panel

`/admin/` opens the dashboard: VPS status, CPU / RAM / disk / network cards and live graphs (worker 1.4.0+ sends them
every 5 s), Live Stream Overview with Start / Stop / Watch, Game Manager (auto play on/off, speed, restart), Schedule
Manager (start and stop a stream at set times, daily or on chosen days, Bangladesh time; overnight windows work),
YouTube Manager, Recent Logs, Start All / Stop All / Restart All, Auto Start / Auto Stop / Notification switches,
monthly bandwidth, and a bell for warnings and problems. Shared pieces live in `app/panel.php`, `app/dash.php`, `app/ui.php`.

## First-time setup

1. Deploy, then open `https://ffteditt.store/admin/` → create the admin login.
2. **Servers** → add a server → copy the install line → paste it into the VPS terminal (as root).
3. **Streams** → **+ Add stream** → paste the YouTube stream key, choose the server → **Start**.

## The game

`https://ffteditt.store/` (the home page) is the 3D Ball Drop game; its files live in `public/game/` (three.js, bundled in `public/game/vendor`). It plays itself:
two balls drop into a glossy bowl, the first to fall into the hole scores, first to the target wins, then it restarts.
Admin → **Game** sets names, colours, shirt numbers, photos (stored privately, served by `/api/image.php`) and the target,
and can reset scores. Open games pick up changes within ~20 s. The bowl is rendered once and only the balls are drawn
each frame, so it streams smoothly on CPU-only servers (the worker starts Chrome with SwiftShader WebGL).

## Country Flag Battle

`https://ffteditt.store/flags/` is the second game (files in `public/flags/`, light 2D canvas). 195 country flags bounce
inside a turning ring with a gap; a flag that slips out is eliminated and the last flag left qualifies for the final.
When the final is full (70 by default) the final round crowns the champion, then a new tournament starts. Progress is
kept in the stream browser, so a restart continues the tournament. Admin → **Flag Battle** sets the title, final size,
ring speed, flag speed and gap size, and can restart the tournament. To stream it, pick **Country Flag Battle** under
*Page to stream* when editing a stream. Flags come from flag-icons (MIT, see `public/flags/FLAG-ICONS-LICENSE.txt`).

## Tubes, sounds and commentator

Two glass tubes hold the balls; each round one ball rolls out of each tube into the bowl. Sound effects are made in
code (Web Audio). An English commentator (Admin → **Voice**, lines for both games; shared code in `public/game/audio.js`) reads lines like "Who wins this round? Comment now!":
the VPS worker makes the voices automatically with Piper (free offline AI voice, installed on first use) and uploads
them; any line can be replaced with your own MP3. Captions show on screen. Each stream gets its own PulseAudio sink,
so the game sound goes into the YouTube stream.

## Web terminal

Admin → **Terminal** runs commands on a VPS from any phone or computer, through the worker (no SSH needed after the first install).
It asks for the admin password again, locks after 15 minutes without a command, logs every command in Activity, and can be
switched off per server on the Servers page. Limits: 60 s per command, no interactive programs (nano, top, less).

## Useful VPS commands

```bash
journalctl -u ffteditt-worker -f      # watch the worker
systemctl restart ffteditt-worker      # restart it
systemctl stop ffteditt-worker         # stop everything on this VPS
```

Rough capacity: one 480p stream needs about 1 CPU core; 720p about 2; 1080p about 3–4.
