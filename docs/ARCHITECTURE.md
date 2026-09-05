# Architecture

## Components

- `frontend/` is a Vite static application. It never connects to Intiface.
- `backend/` contains narrowly routed PHP endpoints and a SQLite queue.
- `relay/` runs only on the private Linux workstation. It opens outbound HTTPS requests and a loopback WebSocket to Intiface Central; it has no inbound listener.
- `data/` contains mutable runtime state and must never be served by nginx.

## Request lifecycle

1. The browser calls `GET /api/status.php` and receives only availability and timers.
2. `POST /api/trigger.php` validates the origin, proxy-derived address, hCaptcha, duration, strength, pattern, cooldown, and device heartbeat.
3. The request is inserted atomically into SQLite as a bounded job.
4. The relay agent polls `/agent/poll.php`, claims one job, and validates it again before sending only Vibrate commands.
5. Completion is acknowledged at `/agent/complete.php`.

## Privacy boundaries

IP addresses are stored only as keyed HMACs. Device names and device metadata never leave the workstation. Nicknames are carried only in the job response to the authenticated local agent. Optional audio uses a five-minute, cookie-bound receipt.
