#!/usr/bin/env bash
# Blue/green cutover: edits docker/greenhood/nginx/active-backend.conf (mounted into the nginx container)
# and reloads nginx. No host-level nginx required.
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
COMPOSE=(docker compose)

if ! docker compose version >/dev/null 2>&1; then
    echo "error: 'docker compose' is required (Compose V2)." >&2
    exit 1
fi

if [[ ! -f "$ACTIVE_BACKEND" ]]; then
    echo "error: active backend file not found: ${ACTIVE_BACKEND}" >&2
    exit 1
fi

get_active_slot() {
    if grep -qE 'openemr_blue:80' "$ACTIVE_BACKEND"; then
        echo blue
        return
    fi
    if grep -qE 'openemr_green:80' "$ACTIVE_BACKEND"; then
        echo green
        return
    fi
    echo "error: could not detect active backend (openemr_blue:80 or openemr_green:80) in ${ACTIVE_BACKEND}" >&2
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

echo "Active: ${ACTIVE} -> starting ${NEW_SERVICE}, then updating ${ACTIVE_BACKEND} and reloading nginx."

if [[ "$TARGET" == green ]]; then
    WAIT_SVC=(--profile standby up -d "$NEW_SERVICE")
else
    WAIT_SVC=(up -d "$NEW_SERVICE")
fi

"${COMPOSE[@]}" "${WAIT_SVC[@]}"
wait_healthy "$NEW_SERVICE"

tmp="$(mktemp)"
if [[ "$TARGET" == green ]]; then
    sed -E 's/openemr_blue:80/openemr_green:80/g' "$ACTIVE_BACKEND" >"$tmp"
else
    sed -E 's/openemr_green:80/openemr_blue:80/g' "$ACTIVE_BACKEND" >"$tmp"
fi
mv "$tmp" "$ACTIVE_BACKEND"

reload_nginx

echo "Upstream now points to ${TARGET}; stopping ${OLD_SERVICE}."
"${COMPOSE[@]}" stop "$OLD_SERVICE"

echo "Done."
