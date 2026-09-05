# Deployment

## Server

1. Serve only `/home/sites/fuckme/frontend/dist` as nginx `root`.
2. Route the explicit PHP endpoints to files under `/home/sites/fuckme/backend`.
3. Keep `/home/sites/fuckme/backend/.env` and `/home/sites/fuckme/data` outside the document root.
4. Create `data/` and `data/audio/` writable by the PHP-FPM user.
5. Install PHP 8.4 with `curl`, `pdo_sqlite`, and `sqlite3`.
6. Reload nginx and restart PHP-FPM after backend changes.

## Local workstation

1. Start Intiface Central on `127.0.0.1:12345`.
2. Install Node.js and `ffmpeg` if audio is enabled.
3. Copy `relay/.env.example` to `relay/.env`.
4. Run `npm ci` and `npm start` in `relay/`.
5. The agent must be allowed outbound HTTPS; no inbound firewall rule is needed.

## Release checklist

```bash
cd frontend && npm ci && npm run build
cd ../relay && npm ci && npm run check
```

Upload `frontend/dist`, backend source, and relay source. Do not upload `.env`, `data/`, logs, or `node_modules/`.
