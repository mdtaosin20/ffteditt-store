# FFTEdit macOS Worker (initial)

This worker is separate from the Linux VPS worker. It is an initial Apple Silicon/macOS integration and does **not** replace `worker/agent.py`.

## Current limitations

- Supports one assigned stream at a time on a Mac worker.
- Captures the shared macOS desktop with FFmpeg `avfoundation`; it does not create isolated invisible displays like Linux/Xvfb.
- Sends silent audio for the first test; desktop/game audio routing is not implemented yet.
- Live chat, Piper voice generation, and remote terminal command execution are not implemented in this initial macOS worker.
- macOS may require Screen Recording permission for Terminal (or whichever process runs Python). If capture fails, check System Settings → Privacy & Security → Screen & System Audio Recording.
- The screen capture input index can vary by FFmpeg/macOS version. Run `ffmpeg -f avfoundation -list_devices true -i ""` and verify the screen device before starting a stream. The initial code currently uses `1:none`; change only after confirming the listed device index.

## Prerequisites

- Apple Silicon or Intel Mac running macOS
- Python 3 and FFmpeg available in PATH
- A server entry/token created from Admin → Servers in the FFTEdit panel

## Configuration

Keep the token private. Create the config file locally; do not commit it to GitHub:

```bash
mkdir -p "$HOME/Library/Application Support/FFTEdit"
chmod 700 "$HOME/Library/Application Support/FFTEdit"
cat > "$HOME/Library/Application Support/FFTEdit/worker.json" <<'EOF'
{
  "panel": "https://ffteditt.store",
  "token": "PASTE_THE_NEW_SERVER_TOKEN_HERE"
}
EOF
chmod 600 "$HOME/Library/Application Support/FFTEdit/worker.json"
```

Replace the placeholder token with the token shown once by Admin → Servers. Never share the token or add the config file to Git.

## Local syntax check

From the repository root:

```bash
python3 -m py_compile worker/macos-agent.py
```

## Run in a terminal for the first test

```bash
python3 worker/macos-agent.py
```

Keep the terminal open during this test. The Mac should appear Online in Admin → Servers after a successful authenticated POST. Stop with Control+C.

Do not use this as a multi-stream or unattended production service yet. Test with a disposable/private YouTube stream first, verify the correct screen device and Screen Recording permission, and confirm the video before using a public stream.
