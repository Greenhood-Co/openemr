#!/usr/bin/env bash
# Blue/green cutover: edits only the host nginx vhost (not other sites).
# Default vhost path: /etc/nginx/sites-enabled/openemr.greenhood.com.ng
#
# Requires: Docker Compose v2, sudo (to edit /etc/nginx and reload nginx).

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

if [[ -f "${ROOT_DIR}/.env" ]]; then
    set -a
    # shellcheck source=/dev/null
    source "${ROOT_DIR}/.env"
    set +a
fi

NGINX_SITE_CONF="${NGINX_SITE_CONF:-/etc/nginx/sites-enabled/openemr.greenhood.com.ng}"
OPENEMR_BLUE_HOST_PORT="${OPENEMR_BLUE_HOST_PORT:-18081}"
OPENEMR_GREEN_HOST_PORT="${OPENEMR_GREEN_HOST_PORT:-18082}"

COMPOSE=(docker compose)

if ! docker compose version >/dev/null 2>&1; then
    echo "error: 'docker compose' is required (Compose V2)." >&2
    exit 1
fi

if [[ ! -f "$NGINX_SITE_CONF" ]]; then
    echo "error: nginx vhost not found: ${NGINX_SITE_CONF}" >&2
    echo "hint: sudo cp ${ROOT_DIR}/nginx/openemr.greenhood.com.ng ${NGINX_SITE_CONF}" >&2
    exit 1
fi

run_priv() {
    if [[ "$(id -u)" -eq 0 ]]; then
        "$@"
    else
        sudo "$@"
    fi
}

get_active_slot() {
    if grep -qE "^[[:space:]]*server 127\\.0\\.0\\.1:${OPENEMR_BLUE_HOST_PORT};" "$NGINX_SITE_CONF"; then
        echo blue
        return
    fi
    if grep -qE "^[[:space:]]*server 127\\.0\\.0\\.1:${OPENEMR_GREEN_HOST_PORT};" "$NGINX_SITE_CONF"; then
        echo green
        return
    fi
    echo "error: no active upstream matching 127.0.0.1:${OPENEMR_BLUE_HOST_PORT} or :${OPENEMR_GREEN_HOST_PORT} in ${NGINX_SITE_CONF}" >&2
    exit 1
}

wait_healthy() {
    local svc="$1"
    local cid
    local i
    local status

    for i in $(seq 1 120); do
        cid="$("${COMPOSE[@]}" ps -q "$svc" 2>/dev/null || true)"
        if [[ -z "$cid" ]]; then
            sleep 3
            continue
        fi

        if docker inspect --format='{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$cid" 2>/dev/null | grep -qx healthy; then
            return 0
        fi

        status="$(docker inspect --format='{{.State.Status}}' "$cid" 2>/dev/null || echo unknown)"
        if [[ "$status" == "restarting" ]]; then
            sleep 3
            continue
        fi

        sleep 3
    done

    echo "error: service ${svc} did not become healthy in time." >&2
    exit 1
}

switch_upstream_to() {
    local target="$1"
    local port
    if [[ "$target" == blue ]]; then
        port="$OPENEMR_BLUE_HOST_PORT"
    else
        port="$OPENEMR_GREEN_HOST_PORT"
    fi
    run_priv sed -i -E "s/^[[:space:]]*server 127\\.0\\.0\\.1:[0-9]+;/    server 127.0.0.1:${port};/" "$NGINX_SITE_CONF"
}

reload_host_nginx() {
    run_priv nginx -t
    run_priv nginx -s reload
}

ACTIVE="$(get_active_slot)"
if [[ "$ACTIVE" == blue ]]; then
    TARGET=green
else
    TARGET=blue
fi

NEW_SERVICE="openemr_${TARGET}"
OLD_SERVICE="openemr_${ACTIVE}"

echo "Active: ${ACTIVE} -> starting ${NEW_SERVICE}, then switching ${NGINX_SITE_CONF}."

if [[ "$TARGET" == green ]]; then
    WAIT_SVC=(--profile standby up -d "$NEW_SERVICE")
else
    WAIT_SVC=(up -d "$NEW_SERVICE")
fi

"${COMPOSE[@]}" "${WAIT_SVC[@]}"
wait_healthy "$NEW_SERVICE"

switch_upstream_to "$TARGET"
reload_host_nginx

if [[ "$TARGET" == blue ]]; then
    _active_port="$OPENEMR_BLUE_HOST_PORT"
else
    _active_port="$OPENEMR_GREEN_HOST_PORT"
fi
echo "Upstream now points to ${TARGET} (127.0.0.1:${_active_port}); stopping ${OLD_SERVICE}."
"${COMPOSE[@]}" stop "$OLD_SERVICE"

echo "Done."
