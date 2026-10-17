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
