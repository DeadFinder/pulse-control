# Configuration

## Backend `.env`

Copy `backend/.env.example` to `backend/.env`. Keep it inside the project, never commit it, and use permissions `640` readable by PHP-FPM.

- `APP_ORIGIN`: exact public HTTPS origin.
- `APP_SECRET`: at least 32 random characters; used for HMAC identity hashes.
- `HCAPTCHA_SECRET`: server-side hCaptcha secret.
- `AGENT_TOKEN`: independent long token shared with the local agent.
- `DB_PATH`: project-local SQLite path, normally `/home/sites/fuckme/data/relay.sqlite`.
- `TRUSTED_PROXIES`: comma-separated proxy CIDRs. Never use an all-address CIDR.
- `COOKIE_SECURE`: keep `true` in production.
- `DIGISELLER_*`: seller credentials and premium product ID.
- `PREMIUM_MAX_DURATION`: bounded premium duration, in seconds.
- `AUDIO_DIR`: project-local temporary audio directory.

## Relay `.env`

Copy `relay/.env.example` to `relay/.env`.

- `RELAY_BASE_URL`: public site URL.
- `INTIFACE_URL`: loopback Intiface WebSocket only.
- `AUDIO_ENABLED`: `false` by default; enable only when desired.
- `AUDIO_INPUT`: PulseAudio source name.
- `RELAY_LOG_FILE`: local log path.
- `DESKTOP_NOTIFICATIONS`: controls `notify-send` notifications.
- `INTIFACE_RETRIES` and `INTIFACE_RECONNECT_INTERVAL_MS`: recovery behavior.
