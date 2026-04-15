# Greenhood OpenEMR — Docker deployment

This document describes the **Greenhood-specific** Docker layout layered on upstream OpenEMR. Keep customizations in `./Dockerfile`, `docker/greenhood/`, and the root `docker-compose.yml` so `git pull` from upstream stays straightforward (do not edit `docker/production/docker-compose.yml` in place; use it only as a reference).

## Why this base

- **Reference:** [docker/production/docker-compose.yml](docker/production/docker-compose.yml) — minimal production-style stack: official `openemr/openemr` image pattern, MariaDB 11.8, healthchecks, persistent volumes.
- **This repo:** Same image family and DB pattern, extended with a **custom image** (root `Dockerfile`) that only adds `docker/greenhood/` assets and an entrypoint wrapper — no forks of upstream library Dockerfiles under `docker/library/`.
- **PostgreSQL** is not used (MariaDB only), per project constraints.

## Architecture

| Piece | Role |
|--------|------|
| `mysql` | MariaDB on fixed `172.29.8.10` (configurable via `OPENEMR_MYSQL_HOST`) for stable DNS on some hosts |
| `openemr_blue` / `openemr_green` | Two app slots sharing `openemr_sites`; only one should run after cutover (see below) |
| `nginx` | Only published service (`NGINX_HTTP_PORT`, default 80); proxies to active slot via `docker/greenhood/nginx/active-backend.conf` |

Volumes: `mariadb_data`, `openemr_sites` (database + site files).

## Prerequisites

- Docker with **Compose v2** (`docker compose`).
- Copy `.env.example` to `.env` and set secrets (especially `MYSQL_*`, `OE_*`, `TRAINING_ACCOUNT_PASSWORD`).

## Build the app image (you run this locally)

```bash
docker compose build openemr_blue
```

The repository intentionally does not run `docker build` in automation here; build on your machine or CI.

## First-time startup (single slot)

Do **not** start `openemr_green` until the first install has finished (shared `sites` volume — avoids installer races):

```bash
docker compose up -d mysql openemr_blue nginx
```

When OpenEMR is up, browse to `http://localhost` (or `http://localhost:${NGINX_HTTP_PORT}`). After blue is healthy, you may add the standby slot:

```bash
set COMPOSE_PROFILES=standby
docker compose up -d openemr_green
```

(On Linux/macOS you can use `COMPOSE_PROFILES=standby docker compose up -d openemr_green`.)

## Blue / green cutover

1. Detects active color from `docker/greenhood/nginx/active-backend.conf` (`openemr_blue:80` vs `openemr_green:80`).
2. Starts the inactive service (`openemr_green` needs `--profile standby` the first time it is created).
3. Waits until Docker reports **healthy** (curl `readyz` in the OpenEMR image).
4. Rewrites `active-backend.conf` to point at the new slot.
5. Reloads Nginx in the `nginx` container.
6. Stops the old app container.

From the repo root:

```bash
bash deploy.sh
```

On Windows, run this from **Git Bash** or WSL (needs `bash`, `sed`, `mktemp`).

## Container init hooks (`/scripts/init/`)

The custom entrypoint (`docker/greenhood/entrypoint-wrapper.sh`) runs, in sorted order, every `*.sh` under `/scripts/init/` **after** `sqlconf.php` exists and `/meta/health/readyz` succeeds. Add new scripts with a numeric prefix (e.g. `30-custom.sh`); keep each script **idempotent** (marker file under the site dir or DB checks).

Shipped scripts:

| Script | Purpose |
|--------|---------|
| `10-seed-demo.sh` | Fictional demo patients, encounters, appointments, one problem (see `contrib/greenhood/seed_demo_data.php`) |
| `20-provision-training-users.sh` | Training logins when `TRAINING_ACCOUNT_PASSWORD` is set (see `provision_training_users.php`) |

Markers (skip on restart):

- `sites/default/.greenhood_demo_seed_complete`
- `sites/default/.greenhood_training_users_complete`

To **re-run** provisioning after a deliberate reset, delete the relevant marker file(s) and the seeded rows (or wipe volumes in dev only).

## Training accounts and demo data

- **Demo data:** Clearly labeled fictional Nigerian-themed names and `GH-DEMO-*` public patient IDs; emails use `@example.invalid`.
- **Training users:** Emails are listed in `docker/greenhood/php/provision_training_users.php`. Password for all is taken **only** from **`TRAINING_ACCOUNT_PASSWORD`** in `.env` (never commit real passwords).
- If a user with the same email already exists, that row is skipped.

## Updating from upstream OpenEMR

1. `git fetch` / `git merge` (or rebase) from upstream.
2. Resolve conflicts in `Dockerfile`, `docker-compose.yml`, `docker/greenhood/`, `deploy.sh`, `GREENHOOD.md`, `.env.example` if touched.
3. Rebuild the image and run migrations as usual for the OpenEMR version.

## Optional: host Nginx (VPS)

If you prefer TLS or extra rules on the host, you can put host Nginx in front of the published Docker port. The legacy example `nginx/openemr.greenhood.com.ng` targeted host proxying to loopback ports; the current design publishes **only** the compose `nginx` service port — point the host vhost at `127.0.0.1:${NGINX_HTTP_PORT}`.

## Environment variables (summary)

See `.env.example` for full comments. Important:

- `MYSQL_*`, `OE_*` — install / admin user
- `TRAINING_ACCOUNT_PASSWORD` — shared password for training accounts (required for `20-provision-training-users.sh` to create users)
- `NGINX_HTTP_PORT` — host port mapped to the compose Nginx container (default 80)
- `OPENEMR_MYSQL_HOST` — default `172.29.8.10` to match the static MariaDB address in `docker-compose.yml`
