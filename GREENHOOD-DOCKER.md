# Greenhood OpenEMR — production-style Docker (blue/green)

This guide documents the **root `Dockerfile`**, **`docker-compose.yml`**, **host nginx vhost** for this app only, and **`./deploy.sh`** for blue/green cutovers. Upstream files under `docker/library` are **not** modified.

> **Note:** The upstream project `README.md` is unchanged. Use this file as the deployment readme for this layout.

## Why this base configuration

| Area | Choice |
|------|--------|
| **Source of truth** | [`docker/production/docker-compose.yml`](docker/production/docker-compose.yml) in this repository |
| **Database** | **MariaDB 11.8**, not PostgreSQL |
| **App image** | Official **`openemr/openemr`**, extended only in the **root `Dockerfile`** |
| **HTTP front door** | **System nginx** on the VPS (`sites-enabled`), so other apps keep their own vhosts. There is **no** nginx service in Compose. |

OpenEMR containers bind **only to loopback** (`127.0.0.1:18081` / `127.0.0.1:18082` by default) so **host nginx** can proxy while MariaDB stays internal.

## Prerequisites

- Docker Engine with **Compose V2** (`docker compose`)
- **nginx** on the VPS (system package), `sudo` for editing `/etc/nginx/...`
- `cp .env.example .env` and set secrets (`.env` is gitignored)

## One-time setup

```bash
cp .env.example .env
# Edit .env: MYSQL_* , OE_PASS , OPENEMR_*_HOST_PORT if you change loopback ports

docker compose build openemr_blue
```

**Install the vhost** (once per server; does not touch `default` or other sites):

```bash
sudo cp nginx/openemr.greenhood.com.ng /etc/nginx/sites-enabled/openemr.greenhood.com.ng
sudo nginx -t && sudo systemctl reload nginx
```

Adjust `listen` / `server_name` in that file if your public port or domain differs. The template uses **`listen 8008`** and **`openemr.greenhood.com.ng`**. The **`server 127.0.0.1:PORT;`** line in `upstream openemr_active` must match **`OPENEMR_BLUE_HOST_PORT`** / **`OPENEMR_GREEN_HOST_PORT`** in `.env` (defaults **18081** / **18082**).

**First startup** (empty site volume — only `openemr_blue`):

```bash
docker compose up -d mariadb openemr_blue
```

When healthy, open **`http://openemr.greenhood.com.ng:8008/`** (or whatever you set in the vhost). Log in with `OE_USER` / `OE_PASS`.

### Initial admin account (Greenhood)

| Variable | Purpose |
|----------|---------|
| `OE_USER` | Login username (e.g. `owner`) |
| `OE_PASS` | Login password (strong secret; do not commit) |
| `OE_ADMIN_DISPLAY_NAME` | Display name after install (e.g. `Greenhood_co`, stored as `fname`) |

## Blue/green deploy

- **`./deploy.sh`** (repo **root**) only edits **`NGINX_SITE_CONF`** (default **`/etc/nginx/sites-enabled/openemr.greenhood.com.ng`**). It does **not** change `nginx.conf`, `default`, or other vhosts.
- Standby slot: **`openemr_green`**, Compose profile **`standby`**.

```bash
chmod +x deploy.sh
./deploy.sh
```

The script:

1. Loads `.env` from the repo (for port numbers)
2. Detects active slot from `server 127.0.0.1:<blue-or-green-port>;` in the vhost file
3. Starts the inactive Compose service and waits until **healthy**
4. **`sudo sed`** updates only that upstream line, then **`sudo nginx -t`** and **`sudo nginx -s reload`**
5. Stops the old app container

Override the vhost path:

```bash
export NGINX_SITE_CONF=/etc/nginx/sites-enabled/openemr.greenhood.com.ng
./deploy.sh
```

## Networking and ports

| Piece | Role |
|-------|------|
| **MariaDB** | Not published to the host |
| **openemr_blue / openemr_green** | `127.0.0.1:OPENEMR_*_HOST_PORT → 80` inside the container |
| **Host nginx** | Listens on **8008** (in the template) and proxies to the active loopback port |

**HTTPS:** Add a `listen 443 ssl` **server** in the same vhost file after certbot; keep other apps’ configs separate.

## Files

| Path | Role |
|------|------|
| `Dockerfile` | `FROM openemr/openemr`, adds `docker/greenhood/entrypoint-wrapper.sh` |
| `docker-compose.yml` | `mariadb`, `openemr_blue`, `openemr_green` (profile `standby`); loopback port mappings |
| `nginx/openemr.greenhood.com.ng` | Template for **`/etc/nginx/sites-enabled/openemr.greenhood.com.ng`** |
| **`deploy.sh`** | Blue/green switch (repo root; edits only that vhost) |
| `.env.example` | Template including `OPENEMR_BLUE_HOST_PORT` / `OPENEMR_GREEN_HOST_PORT` |

## Upstream updates

- `git pull` then **`docker compose build openemr_blue`** when the base image or root `Dockerfile` changes.
- Keep customizations in the root `Dockerfile` and `docker-compose.yml`, not in `docker/library`.

## Troubleshooting

- **“nginx vhost not found”:** Copy `nginx/openemr.greenhood.com.ng` to `/etc/nginx/sites-enabled/` (see above).
- **“could not find openemr.sh”:** See [`docker/greenhood/entrypoint-wrapper.sh`](docker/greenhood/entrypoint-wrapper.sh) and verify the parent image layout.
- **Health check fails on HTTP:** Align `healthcheck` with [`docker/production/docker-compose.yml`](docker/production/docker-compose.yml) if the app only answers on HTTPS internally.
