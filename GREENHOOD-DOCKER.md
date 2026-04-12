# Greenhood OpenEMR — production-style Docker (blue/green)

This guide documents the **root `Dockerfile`**, **`docker-compose.yml`**, **Nginx** front door, and **blue/green** cutover script. Upstream files under `docker/library` and other stock OpenEMR Docker trees are **not** modified.

> **Note:** The upstream project `README.md` is unchanged. Use this file as the deployment readme for this layout.

## Why this base configuration

| Area | Choice |
|------|--------|
| **Source of truth** | [`docker/production/docker-compose.yml`](docker/production/docker-compose.yml) in this repository |
| **Database** | **MariaDB 11.8** (same lineage as the official production example), not PostgreSQL |
| **App image** | Official **`openemr/openemr`** production image, extended only in the **root `Dockerfile`** |
| **Not used for production here** | `development-easy`, `development-easy-redis`, `development-insane`, etc. — those are for local development (bind mounts, Xdebug, optional Redis, extra services) |

The production example already separates **database** and **app**, uses **health checks**, and matches [`DOCKER_README.md`](DOCKER_README.md). This setup adds **Nginx**, **two identical app services**, and **`scripts/deploy.sh`** for cutovers.

## Prerequisites

- Docker Engine with **Compose V2** (`docker compose`)
- On the VPS: `cp .env.example .env` and set strong secrets (`.env` is gitignored)

## One-time setup

```bash
cp .env.example .env
# Edit .env: MYSQL_* , OE_PASS , etc.

docker compose build openemr_blue
```

**First startup** (empty volumes — start **only** `openemr_blue` so one auto-installer runs):

```bash
docker compose up -d mariadb openemr_blue nginx
```

When `openemr_blue` is healthy, open **`http://openemr.greenhood.com.ng:${HTTP_PORT:-8008}/`** (set `HTTP_PORT` in `.env`; default in compose is **8008**). Log in with `OE_USER` / `OE_PASS`.

### Initial admin account (Greenhood)

In `.env`, set:

| Variable | Purpose |
|----------|---------|
| `OE_USER` | Login username (e.g. `owner`) |
| `OE_PASS` | Login password (use a strong secret; do not commit it) |
| `OE_ADMIN_DISPLAY_NAME` | Display name after install (e.g. `Greenhood_co`, stored as `fname` on that user) |

The official image creates the admin from `OE_USER` / `OE_PASS`. The custom entrypoint optionally updates the display name once the user row exists in the database.

## Blue/green deploy

- The **active** backend is the `server openemr_blue:80;` or `server openemr_green:80;` line inside the `upstream openemr_active` block in `nginx/default.conf` (updated by `deploy.sh`).
- The standby slot is **`openemr_green`**, gated by the Compose profile **`standby`**, so it does not start on first install.

```bash
chmod +x scripts/deploy.sh
./scripts/deploy.sh
```

The script:

1. Reads active color from `nginx/default.conf` (`server openemr_*:80;`)
2. Starts the inactive service (`docker compose --profile standby up -d openemr_green` when switching to green)
3. Waits until Docker reports **healthy**
4. Patches the active `server` line in `nginx/default.conf` and runs **`nginx -s reload`**
5. Stops the old app container

After changing the root `Dockerfile` or base image tag:

```bash
docker compose build openemr_blue
./scripts/deploy.sh
```

## Networking and ports

- **Published:** Nginx **HTTP** on the host as `HTTP_PORT` (**default `8008`** → container `80`). MariaDB and OpenEMR are **not** published to the host. `server_name` is **`openemr.greenhood.com.ng`** in `nginx/default.conf`.
- **HTTPS:** Not enabled until you add certificates (e.g. **certbot**). `nginx/default.conf` includes a commented `listen 443 ssl` example; then add `"443:443"` (and cert volume mounts) under `nginx` → `ports` in `docker-compose.yml`.

## Files

| Path | Role |
|------|------|
| `Dockerfile` | `FROM openemr/openemr`, adds `docker/greenhood/entrypoint-wrapper.sh` only |
| `docker-compose.yml` | `mariadb`, `openemr_blue`, `openemr_green` (profile `standby`), `nginx` |
| `docker/greenhood/entrypoint-wrapper.sh` | Delegates to upstream `openemr.sh`; optional display-name patch |
| `nginx/default.conf` | Reverse proxy + `upstream openemr_active` (active `server` line toggled by `deploy.sh`) |
| `scripts/deploy.sh` | Blue/green switch |
| `.env.example` | Variable template for `.env` |

## Upstream updates

- `git pull` updates application code and `docker/` examples; **rebuild** `openemr_blue` when you change the `FROM` image or root `Dockerfile`.
- Keep customizations in the root `Dockerfile` and root `docker-compose.yml`, not in `docker/library`.

## Troubleshooting

- **“could not find openemr.sh”:** The official image layout may have changed. Run  
  `docker run --rm --entrypoint ls openemr/openemr:latest -la /`  
  and adjust `docker/greenhood/entrypoint-wrapper.sh` to invoke the correct upstream script (without editing trees under `docker/library`).
- **Health check fails:** If the app only answers on HTTPS internally, align the Compose `healthcheck` with [`docker/production/docker-compose.yml`](docker/production/docker-compose.yml) (`curl` to `https://localhost/...` with `--insecure`).
