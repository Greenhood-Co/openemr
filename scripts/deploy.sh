#!/usr/bin/env bash
# Blue/green cutover for docker-compose.yml in the repository root.
# Requires Docker Compose v2 (`docker compose`).

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

ACTIVE_FILE="${ROOT_DIR}/nginx/default.conf"
COMPOSE=(docker compose)

if ! docker compose version >/dev/null 2>&1; then
    echo "error: 'docker compose' is required (Compose V2)." >&2
    exit 1
fi

if [[ ! -f "$ACTIVE_FILE" ]]; then
    echo "error: missing ${ACTIVE_FILE}" >&2
    exit 1
fi

get_active_slot() {
    if grep -qE '^[[:space:]]*server openemr_blue:80;' "$ACTIVE_FILE"; then
        echo blue
        return
    fi
    if grep -qE '^[[:space:]]*server openemr_green:80;' "$ACTIVE_FILE"; then
        echo green
        return
    fi
    echo "error: could not find 'server openemr_blue:80;' or 'server openemr_green:80;' in ${ACTIVE_FILE}" >&2
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
    sed -i -E "s/^[[:space:]]*server openemr_(blue|green):80;/    server openemr_${target}:80;/" "$ACTIVE_FILE"
}

reload_nginx() {
    "${COMPOSE[@]}" exec nginx nginx -s reload
}

ACTIVE="$(get_active_slot)"
if [[ "$ACTIVE" == blue ]]; then
    TARGET=green
else
    TARGET=blue
fi

NEW_SERVICE="openemr_${TARGET}"
OLD_SERVICE="openemr_${ACTIVE}"

echo "Active: ${ACTIVE} -> starting ${NEW_SERVICE}, then switching Nginx."

if [[ "$TARGET" == green ]]; then
    WAIT_SVC=(--profile standby up -d "$NEW_SERVICE")
else
    WAIT_SVC=(up -d "$NEW_SERVICE")
fi

"${COMPOSE[@]}" "${WAIT_SVC[@]}"
wait_healthy "$NEW_SERVICE"

switch_upstream_to "$TARGET"
reload_nginx

echo "Upstream now points to ${TARGET}; stopping ${OLD_SERVICE}."
"${COMPOSE[@]}" stop "$OLD_SERVICE"

echo "Done."
