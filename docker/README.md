# Docker stack: nginx (HTTPS proxy) + MySQL

Fronts the `panda` server with an HTTPS reverse proxy and runs a standalone MySQL.
`panda.exe` itself runs on the **Windows host** (it needs adb/USB), not in a container —
nginx reaches it via `host.docker.internal:8080`.

## First run

```powershell
cd docker
copy .env.example .env      # then edit MySQL passwords
.\gen-certs.ps1             # one-time self-signed cert for localhost
docker compose up -d
```

Make sure `panda.exe` is running on the host first (`..\start.ps1`), then open:

- https://localhost  (self-signed cert — browser will warn once; accept it)

HTTP on port 80 redirects to HTTPS.

## Services

| Service | Port | Notes |
|---|---|---|
| nginx | 80, 443 | Proxies `/` and `/ws/{id}` to `host.docker.internal:8080`. WebSocket-aware. |
| mysql | 3306 | Empty DB `panda` + user `panda`. Data persists in the `mysql-data` volume. Not yet wired into panda. |

## Common commands

```powershell
docker compose ps
docker compose logs -f nginx
docker compose down          # stop (keeps mysql-data volume)
docker compose down -v       # stop and delete the MySQL volume
```

## Notes

- The self-signed cert covers `localhost` / `127.0.0.1`. Browsers require HTTPS
  (a "secure context") for some WebCodecs features, which is the reason for TLS here.
- `.env` and `nginx/certs/` are gitignored (secrets / per-machine).
