# Important Files

## Frontend

- `frontend/index.html`: accessible page structure, controls, waveform canvas, captcha mount, and offline screen.
- `frontend/src/main.js`: browser state, API calls, cooldown timers, premium unlock, waveform editing, localStorage persistence, audio receipt polling, and device-offline behavior.
- `frontend/src/styles.css`: monochrome responsive visual system, section layout, waveform editor, notifications, and charging screen.
- `frontend/.env.example`: public build-time hCaptcha site key. Never put a secret here.

## Backend

- `backend/lib/config.php`: project-local `.env` loader and typed application configuration.
- `backend/lib/bootstrap.php`: security headers, SQLite setup/migrations, trusted proxy parsing, cookie identity, hCaptcha verification, and common response helpers.
- `backend/api/status.php`: public availability, cooldown, busy, and device state response.
- `backend/api/trigger.php`: same-origin trigger validation, cooldown transaction, waveform clamping, job creation, and audio receipt creation.
- `backend/api/premium.php`: DigiSeller invoice verification and one-use entitlement binding.
- `backend/api/audio.php`: cookie-bound five-minute audio retrieval.
- `backend/api/test-premium.php`: temporary testing-only grant; remove it after testing.
- `backend/agent/poll.php`: authenticated job claim and device-safe queue polling.
- `backend/agent/complete.php`: completion acknowledgement; failed jobs return to pending until their TTL expires.
- `backend/agent/heartbeat.php`: stores only a boolean device availability heartbeat.
- `backend/agent/audio.php`: authenticated bounded WebM upload from the local agent.
- `backend/lib/digiseller.php`: server-to-server DigiSeller login and invoice lookup.
- `backend/.env.example`: backend configuration template. Copy to `.env`, never commit the real file.

## Relay

- `relay/src/agent.js`: outbound polling agent, Intiface rediscovery/reconnect loop, bounded waveform playback, optional audio capture, local logs, and notifications.
- `relay/.env.example`: workstation configuration template.
- `relay/intiface-relay.service`: optional systemd unit. Adjust paths and user before installing.
- `relay/logs/relay.log`: runtime-only local log; ignored by Git.

## Runtime and deployment

- `data/relay.sqlite`: runtime queue/state database; ignored and never served.
- `data/audio/`: short-lived audio files; ignored and never served.
- `.gitignore`: prevents secrets, dependencies, builds, databases, logs, and editor files from entering Git.
