# API Reference

All responses are JSON except successful audio retrieval. Public API calls are same-origin. Agent calls require `Authorization: Bearer AGENT_TOKEN`.

## Public endpoints

### `GET /api/status.php`

Returns `serverTime`, `busyRemaining`, `cooldownRemaining`, and `deviceOnline`.

### `POST /api/trigger.php`

JSON body:

```json
{
  "duration": 10,
  "intensity": 80,
  "nickname": "Optional name",
  "premium": false,
  "mode": "direct",
  "pattern": [{"t": 0, "v": 0.8}, {"t": 1, "v": 0.8}],
  "captchaToken": "..."
}
```

The server clamps free patterns to the duration-dependent maximum and atomically reserves cooldown. Success returns an opaque `audioToken` when audio is enabled.

### `GET /api/premium.php`

Returns the configured product and checkout URL.

### `POST /api/premium.php`

JSON body `{ "invoiceId": "..." }`. The server checks the invoice with DigiSeller, verifies product ID and paid state, and binds one entitlement to the browser cookie.

### `GET /api/audio.php?token=...`

Only the cookie that created the trigger may retrieve the ready clip. It expires after five minutes.

## Agent endpoints

- `POST /agent/poll.php` claims one pending job.
- `POST /agent/complete.php` marks a job done or returns a failed job to the pending queue.
- `POST /agent/heartbeat.php` publishes only online/offline state.
- `POST /agent/audio.php?id=JOB_ID` uploads a bounded `audio/webm` payload.
