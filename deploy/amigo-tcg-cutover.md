# AMIGO TCG cutover: from tcgvault.cativo.dev to amigotcg.cativo.dev

Read this before the release that renames the product. Every step that touches the
server is done by hand, one at a time, outside the nightly windows (00:00–01:00,
03:00–03:15, 04:00–04:30, 20:30–21:00, 21:45 UTC), and not on a day another release
changed the host. The release has no migration.

## What changes and what does not

| Changes | Stays |
|---|---|
| Public host: `amigotcg.cativo.dev` (the old host redirects) | Host directory `~/deploy/tcg-vault` and compose project `tcg-vault` |
| Image: `cativo23/amigo-tcg` | Volumes (`tcg-vault_pgdata`, ...), database and user `tcg_vault` |
| `APP_NAME`, `APP_URL`, `SESSION_DOMAIN`, mail sender name, tcgcsv user agent | `TCGVAULT_*` variable names, `MAIL_FROM_ADDRESS=tcgvault@cativo.dev` |
| Docker networks (`amigotcg-internal`, `amigotcg-egress`), Traefik router names | Session cookie, cache, Redis and Horizon prefixes (pinned to the old values) |
| Browser storage keys (`amigo-tcg-theme`, `amigo-tcg-cal`) | `demo@tcg-vault.invalid` (existing rows are matched against it) |

DNS needs no change: a wildcard under `cativo.dev` already resolves both names to the
same address. Traefik issues the certificate for the new host on its first request.
Verified 2026-11-25 on the host: `letsencryptresolver` uses `tlsChallenge` (TLS-ALPN-01
on port 443), so no DNS API or port 80 is involved and the wildcard record is enough.
The first request to `https://amigotcg.cativo.dev` waits a few seconds for the issue.

## Steps, in order, with how to undo each

| # | Step (by hand, on the server) | Undo | What is lost if it goes wrong |
|---|---|---|---|
| 1 | Copy `.env` to a dated backup, then add `SESSION_COOKIE=tcg-vault-session`, `CACHE_PREFIX=tcg-vault-cache-`, `REDIS_PREFIX=tcg-vault-database-`, `HORIZON_PREFIX=tcg_vault_horizon:` | Restore the backup | Nothing: the pins equal today's derived values |
| 2 | Check the four pins by comparing against the running config: `docker compose exec app php artisan tinker --execute="echo config('session.cookie').' '.config('cache.prefix').' '.config('database.redis.options.prefix').' '.config('horizon.prefix');"` must print the pinned values with the **old** `APP_NAME` | n/a (read only) | n/a |
| 3 | Set `APP_NAME="AMIGO TCG"`, `APP_URL`, `SESSION_DOMAIN`, `MAIL_FROM_NAME`, `TCGCSV_USER_AGENT` as in `docker/prod/.env.production.example` | Restore the backup | Nothing until step 4 |
| 4 | Publish the release (deploys the new compose: `app`, `horizon`, `scheduler` recreated, two networks renamed) | Redeploy the previous release tag from the old image `cativo23/tcg-vault` with the dated compose copy and the backed-up `.env` | Members must sign in again once on the new host (cookies are per host); theme and calibration reset once |
| 5 | Verify (below) | | |

**Do not**, at any step: rename `~/deploy/tcg-vault`, change `name: tcg-vault` in the
compose file, or rename a volume. Postgres would start on an empty volume.

## Verify after step 4

```bash
curl -sI "https://tcgvault.cativo.dev/cativo23/activity?range=3m" | sed -n '1p;/^location/Ip'
# HTTP/2 301
# location: https://amigotcg.cativo.dev/cativo23/activity?range=3m
curl -sI https://amigotcg.cativo.dev/ | sed -n 1p            # HTTP/2 200
echo | openssl s_client -connect amigotcg.cativo.dev:443 -servername amigotcg.cativo.dev 2>/dev/null | openssl x509 -noout -subject -dates
```

Then, in order: the owner signs in; Horizon shows jobs running and no pile of
waiting jobs under an unfamiliar prefix; `php artisan schedule:list` is unchanged; the
next morning the nightly sync, the backup freshness check and `prices:report-coverage`
raised no Discord alert.

## After the release

The GitHub repo is renamed just before the rename PR merges (GitHub redirects the old
URL and the git remote is updated); the local folder is renamed last, between sessions,
once the deploy is verified. The old image `cativo23/tcg-vault` stays on the registry
for rollback.
