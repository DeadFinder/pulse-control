# Pulse Control

> A private, outbound-only relay for timed Intiface vibration triggers.

Pulse Control is a small Vite + PHP + SQLite application that lets an approved public interface submit a bounded vibration request to a local Intiface Central instance. The local relay agent polls the server; the server never opens a connection to the private workstation.

![Status](https://img.shields.io/badge/status-self--hosted-171714?style=flat-square)
![Frontend](https://img.shields.io/badge/frontend-Vite-171714?style=flat-square)
![Backend](https://img.shields.io/badge/backend-PHP%208.4-171714?style=flat-square)
![Transport](https://img.shields.io/badge/transport-outbound--only-171714?style=flat-square)

## Highlights

- 5-20 second free triggers with a duration-dependent strength ceiling.
- Optional premium, one-use DigiSeller entitlement for extended duration.
- Direct, preset, and editable waveform modes.
- Atomic five-minute cooldown by HMAC-hashed IP and opaque cookie.
- Global busy lock so only one vibration can run at a time.
- hCaptcha verification and strict server-side validation.
- Device heartbeat with charging/offline state.
- Optional three-second voice-activity-filtered reaction audio, retained for five minutes.
- Optional nickname, local timestamped logs, and Linux desktop notifications.
- No public listener on the Intiface workstation.

## Architecture

```text
Browser -> HTTPS proxy -> nginx/PHP -> SQLite queue
                                      ^
Local relay agent -> HTTPS polling --+
       |
       +--> ws://127.0.0.1:12345 -> Intiface Central
```

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the full data flow and privacy boundaries.

## Repository layout

```text
frontend/       Vite application and production build
backend/        PHP API, queue, DigiSeller verification
relay/          Local Node.js agent and systemd unit
data/           Runtime SQLite/audio data; ignored by Git
docs/            Configuration, API, deployment, and security guides
```

## Quick start

### Frontend

```bash
cd frontend
cp .env.example .env
# Set VITE_HCAPTCHA_SITE_KEY in .env
npm ci
npm run build
```

### Backend

```bash
cp backend/.env.example backend/.env
mkdir -p data/audio
chmod 640 backend/.env
```

Fill all production secrets in `backend/.env`. The PHP service account needs read access to the backend and read/write access to `data/`.

### Relay agent

```bash
cd relay
cp .env.example .env
# Set RELAY_BASE_URL, RELAY_AGENT_TOKEN, and INTIFACE_URL
npm ci
npm run check
npm start
```

Keep Intiface bound to loopback. Optional audio requires `ffmpeg`; optional desktop notifications require `notify-send`.

## Deployment

Use nginx with `/home/sites/fuckme/frontend/dist` as the only document root and explicit FastCGI locations for the PHP endpoints. The complete guide is [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

## API and configuration

- Endpoint contracts: [`docs/API.md`](docs/API.md)
- Environment variables: [`docs/CONFIGURATION.md`](docs/CONFIGURATION.md)
- Security model: [`docs/SECURITY.md`](docs/SECURITY.md)

## Development checks

```bash
cd frontend && npm run build
cd ../relay && npm run check
```

PHP syntax can be checked on a machine with PHP installed:

```bash
for file in backend/lib/*.php backend/api/*.php backend/agent/*.php; do php -l "$file" || exit 1; done
```

## Important security rule

Never commit `.env`, SQLite files, local logs, audio, API keys, or agent tokens. If a secret is exposed, rotate it immediately. The repository deliberately ignores runtime data and dependencies; verify with `git status --ignored` before publishing.

## License

Add the license you intend to use before publishing this repository.
