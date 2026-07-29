#!/usr/bin/env bash
# Blue/green cutover: edits docker/greenhood/nginx/active-backend.conf (mounted into the nginx container)
# and reloads nginx. No host-level nginx required.
#
# The cutover is verified through nginx and rolled back if the new slot cannot be served,
# so a failed deploy leaves the previous slot live instead of a 502.
#
# Requires: Docker Compose v2, bash, sed, grep.
#
# Usage (from repo root):
#   ./deploy.sh

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

if [[ -f "${ROOT_DIR}/.env" ]]; then
    set -a
    # shellcheck source=/dev/null
    source "${ROOT_DIR}/.env"
    set +a
fi

ACTIVE_BACKEND="${ACTIVE_BACKEND_CONF:-${ROOT_DIR}/docker/greenhood/nginx/active-backend.conf}"
ACTIVE_BACKEND_TEMPLATE="${ROOT_DIR}/docker/greenhood/nginx/active-backend.conf.example"
BLUE_ADDR="172.29.8.11:80"
GREEN_ADDR="172.29.8.12:80"
COMPOSE=(docker compose)

if ! docker compose version >/dev/null 2>&1; then
    echo "error: 'docker compose' is required (Compose V2)." >&2
    exit 1
fi

if [[ ! -f "$ACTIVE_BACKEND" ]]; then
    if [[ ! -f "$ACTIVE_BACKEND_TEMPLATE" ]]; then
        echo "error: no active backend file (${ACTIVE_BACKEND}) and no template (${ACTIVE_BACKEND_TEMPLATE})." >&2
        exit 1
    fi
    echo "No ${ACTIVE_BACKEND}; seeding it from the template."
    cp "$ACTIVE_BACKEND_TEMPLATE" "$ACTIVE_BACKEND"
fi

get_active_slot() {
    if grep -qF "$BLUE_ADDR" "$ACTIVE_BACKEND"; then
        echo blue
        return
    fi
    if grep -qF "$GREEN_ADDR" "$ACTIVE_BACKEND"; then
        echo green
        return
    fi
    echo "error: could not detect active backend (${BLUE_ADDR} or ${GREEN_ADDR}) in ${ACTIVE_BACKEND}" >&2
    exit 1
}

# Truncate and rewrite the existing file rather than replacing it. `mv` and `sed -i`
# create a new inode, and nginx would keep serving the old upstream indefinitely.
set_backend_addr() {
    local addr="$1"
    local rendered
    rendered="$(sed -E "s/172\.29\.8\.1[12]:80/${addr}/g" "$ACTIVE_BACKEND")"
    printf '%s\n' "$rendered" >"$ACTIVE_BACKEND"
}

restore_backend_addr() {
    printf '%s\n' "$PREVIOUS_BACKEND" >"$ACTIVE_BACKEND"
}

# Proves nginx itself can reach the slot; a healthy app container is not enough.
verify_through_nginx() {
    local i
    for i in $(seq 1 15); do
        if "${COMPOSE[@]}" exec -T nginx wget -q -O /dev/null http://127.0.0.1/upstream-health; then
            return 0
        fi
        sleep 2
    done
    return 1
}

wait_healthy() {
    local svc="$1"
    local cid
    local i
    local status
    local health_log

    echo "Waiting for ${svc} to become healthy..."
    for i in $(seq 1 240); do
        cid="$("${COMPOSE[@]}" ps -q "$svc" 2>/dev/null || true)"
        if [[ -z "$cid" ]]; then
            echo "  [$(printf "%3d" $((i * 3)))] container not yet started..."
            sleep 3
            continue
        fi

        status="$(docker inspect --format='{{.State.Status}}' "$cid" 2>/dev/null || echo unknown)"

        if [[ "$status" == "restarting" ]]; then
            echo "  [$(printf "%3d" $((i * 3)))] container restarting..."
            sleep 3
            continue
        fi

        health_status="$(docker inspect --format='{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$cid" 2>/dev/null)"
        if echo "$health_status" | grep -qx healthy; then
            echo "  ${svc} is healthy!"
            return 0
        fi

        if [[ $((i % 10)) -eq 0 ]]; then
            health_log="$(docker inspect --format='{{range .State.Health.Log}}{{.Output | printf "%.100s"}}{{end}}' "$cid" 2>/dev/null || true)"
            echo "  [$(printf "%3d" $((i * 3)))s] status=${status} health=${health_status} last-check=\"${health_log}\""
        fi
        sleep 3
    done

    echo "error: service ${svc} did not become healthy in time." >&2
    exit 1
}

reload_nginx() {
    "${COMPOSE[@]}" exec -T nginx nginx -s reload
}

ACTIVE="$(get_active_slot)"
if [[ "$ACTIVE" == blue ]]; then
    TARGET=green
else
    TARGET=blue
fi

NEW_SERVICE="openemr_${TARGET}"
OLD_SERVICE="openemr_${ACTIVE}"

PREVIOUS_BACKEND="$(cat "$ACTIVE_BACKEND")"

echo "Active: ${ACTIVE} -> building image, then starting ${NEW_SERVICE}, updating ${ACTIVE_BACKEND} and reloading nginx."

"${COMPOSE[@]}" build

echo "Starting ${NEW_SERVICE}..."
"${COMPOSE[@]}" up -d "$NEW_SERVICE"
wait_healthy "$NEW_SERVICE"

if [[ -n "${TRAINING_ACCOUNT_PASSWORD:-}" ]]; then
    echo "Provisioning training users on ${NEW_SERVICE}..."
    # OpenEMR RootCliGuard forbids PHP CLI as UID 0; run as the web user.
    "${COMPOSE[@]}" exec -T "$NEW_SERVICE" \
        su -s /bin/sh apache -c \
        'php /var/www/localhost/htdocs/openemr/contrib/greenhood/provision_training_users.php'
fi

if [[ "$TARGET" == green ]]; then
    set_backend_addr "$GREEN_ADDR"
else
    set_backend_addr "$BLUE_ADDR"
fi

reload_nginx

if ! verify_through_nginx; then
    echo "error: nginx cannot serve ${TARGET}; rolling back to ${ACTIVE} and leaving ${OLD_SERVICE} running." >&2
    restore_backend_addr
    reload_nginx
    exit 1
fi

echo "Upstream now points to ${TARGET}; stopping ${OLD_SERVICE}."
"${COMPOSE[@]}" stop "$OLD_SERVICE"

echo "Done."
