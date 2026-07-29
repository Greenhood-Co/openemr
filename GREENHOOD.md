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

`active-backend.conf` is **untracked live state**, seeded from `active-backend.conf.example`. It is
mounted through the directory `docker/greenhood/nginx` → `/etc/nginx/greenhood`, and `deploy.sh`
rewrites it **in place**. Both details matter: a single-file bind mount follows the inode, so
replacing the file (`mv`, `sed -i`) leaves nginx serving the old slot, and a tracked copy would be
reverted to blue by `git pull` while blue is stopped.

Volumes: `mariadb_data`, `openemr_sites` (database + site files).

## Prerequisites

- Docker with **Compose v2** (`docker compose`).
- Copy `.env.example` to `.env` and set secrets (especially `MYSQL_*`, `OE_*`, `TRAINING_ACCOUNT_PASSWORD`).
- Seed the routing file once per checkout (it is gitignored, and nginx will not start without it):

```bash
cp docker/greenhood/nginx/active-backend.conf.example docker/greenhood/nginx/active-backend.conf
```

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

When OpenEMR is up, browse to `http://localhost` (or `http://localhost:${NGINX_HTTP_PORT}`). After blue is healthy, add the standby slot:

```bash
docker compose up -d openemr_green
```

## Blue / green cutover

1. Detects active color from `docker/greenhood/nginx/active-backend.conf` (`172.29.8.11` = blue, `172.29.8.12` = green).
2. Starts the inactive service.
3. Waits until Docker reports **healthy** (curl `readyz` in the OpenEMR image).
4. Rewrites `active-backend.conf` in place to point at the new slot.
5. Reloads Nginx in the `nginx` container.
6. Verifies `/upstream-health` **through nginx**, and rolls the file back (leaving the old slot running) if the new slot cannot be served.
7. Stops the old app container.

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
- **Training users:** Usernames are listed in `docker/greenhood/php/provision_training_users.php`. Password for all is taken **only** from **`TRAINING_ACCOUNT_PASSWORD`** in `.env` (never commit real passwords).
- If a user with the same username already exists, that row is skipped.
- **Adding users after deployment:** Open `/add/` while signed in with user-administration permission. Paste comma-, space-, or newline-separated usernames, autofill the rows, and assign individual, random, or common roles. This writes directly to the database, so no rebuild is needed after the page has been deployed once.

## Troubleshooting a 502 after cutover

A 502 means nginx is proxying to a slot that is not serving. Compare what the **container** reads
with what is running — if these disagree, the file was replaced instead of rewritten in place:

```bash
docker compose exec nginx grep proxy_pass /etc/nginx/greenhood/active-backend.conf
docker compose ps
```

Then check the slot directly (`172.29.8.11` = blue, `172.29.8.12` = green):

```bash
docker compose exec nginx wget -qO- http://172.29.8.12:80/meta/health/readyz
```

To repoint nginx by hand, **truncate in place** — `mv` and `sed -i` create a new inode:

```bash
printf '%s\n' "$(sed 's/172\.29\.8\.11:80/172.29.8.12:80/g' docker/greenhood/nginx/active-backend.conf)" \
  > docker/greenhood/nginx/active-backend.conf
docker compose exec nginx nginx -s reload
```

Note that a fresh app container needs several minutes before it answers: the healthcheck allows a
3-minute `start_period`, so "connection refused" right after `up -d` is expected.

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
