# Security Notes

- Rotate any secret that has ever been pasted into chat, a ticket, shell history, or a public repository.
- Configure the edge proxy to replace `X-Forwarded-For`; the origin trusts only configured proxy CIDRs.
- Never expose the SQLite file, audio directory, backend source, or `.env` through nginx.
- Keep the agent token separate from `APP_SECRET` and DigiSeller credentials.
- The server validates all bounds again; browser controls are not security controls.
- The queue uses an immediate SQLite transaction for cooldown and global busy checks.
- Audio is optional, private to the initiating cookie, limited in size, and expires after five minutes.
- A distributed botnet can still consume valid requests; use proxy/CDN rate limits as an additional layer.
- Test endpoint files must be removed after testing, including `backend/api/test-premium.php`.
