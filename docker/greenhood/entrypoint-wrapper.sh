#!/bin/sh
# Chains to the official OpenEMR image entrypoint and optionally patches the
# initial admin display name after auto-install (OE_ADMIN_DISPLAY_NAME).
#
# Also runs optional hooks in /scripts/init/ (sorted *.sh) in the background after
# Apache answers /meta/health/readyz. Each script should be idempotent (marker files
# or DB checks).

greenhood_run_init_scripts() {
    INIT_DIR="/scripts/init"
    if [ ! -d "$INIT_DIR" ]; then
        return 0
    fi

    SITE_DIR="${GREENHOOD_SITE_DIR:-/var/www/localhost/htdocs/openemr/sites/default}"
    OM_ROOT="/var/www/localhost/htdocs/openemr"
    export OPENEMR_ROOT="$OM_ROOT"
    export OE_SITE="${OE_SITE:-default}"

    (
        i=0
        while [ "$i" -lt 720 ]; do
            if [ -f "${SITE_DIR}/sqlconf.php" ] \
                && command -v curl >/dev/null 2>&1 \
                && curl -sf "http://127.0.0.1/meta/health/readyz" >/dev/null 2>&1; then
                break
            fi
            i=$((i + 1))
            sleep 5
        done
        if [ "$i" -ge 720 ]; then
            echo "greenhood-openemr-entrypoint: init scripts skipped (OpenEMR not ready in time)." >&2
            return 0
        fi
        for f in $(ls "$INIT_DIR" 2>/dev/null | LC_ALL=C sort); do
            case "$f" in
                *.sh) ;;
                *) continue ;;
            esac
            fp="$INIT_DIR/$f"
            if [ ! -f "$fp" ]; then
                continue
            fi
            if [ ! -x "$fp" ]; then
                chmod +x "$fp" 2>/dev/null || true
            fi
            echo "greenhood-openemr-entrypoint: running $fp" >&2
            /bin/sh "$fp" || echo "greenhood-openemr-entrypoint: WARNING: $fp exited non-zero" >&2
        done
    ) &
}

patch_admin_display_name() {
    [ -n "${OE_ADMIN_DISPLAY_NAME:-}" ] || return 0

    MARKER="/var/www/localhost/htdocs/openemr/sites/default/.greenhood_display_patched"
    if [ -f "$MARKER" ]; then
        return 0
    fi

    esc_sql() {
        printf '%s' "$1" | sed "s/'/''/g"
    }

    i=0
    while [ "$i" -lt 720 ]; do
        if [ -f /var/www/localhost/htdocs/openemr/sites/default/sqlconf.php ] \
            && command -v mysql >/dev/null 2>&1; then
            u="${OE_USER:-owner}"
            db="${MYSQL_DATABASE:-openemr}"
            esc_u=$(esc_sql "$u")
            esc_n=$(esc_sql "$OE_ADMIN_DISPLAY_NAME")

            export MYSQL_PWD="${MYSQL_PASS:-}"
            cnt=$(mysql -h"${MYSQL_HOST}" -u"${MYSQL_USER}" "$db" -Nse \
                "SELECT COUNT(*) FROM users WHERE username='${esc_u}' AND active = 1" 2>/dev/null || true)
            unset MYSQL_PWD

            if echo "$cnt" | tr -d '[:space:]' | grep -qx '1'; then
                export MYSQL_PWD="${MYSQL_PASS:-}"
                mysql -h"${MYSQL_HOST}" -u"${MYSQL_USER}" "$db" -e \
                    "UPDATE users SET fname='${esc_n}', lname='' WHERE username='${esc_u}' LIMIT 1" \
                    && touch "$MARKER"
                unset MYSQL_PWD
                return 0
            fi
        fi
        i=$((i + 1))
        sleep 5
    done
    return 0
}

if [ -n "${OE_ADMIN_DISPLAY_NAME:-}" ]; then
    ( patch_admin_display_name ) &
fi

greenhood_run_init_scripts

# Quick-setup retries can leave /tmp/php-file-cache as a non-directory; mkdir then fails forever.
if [ -e /tmp/php-file-cache ] && [ ! -d /tmp/php-file-cache ]; then
    rm -f /tmp/php-file-cache
fi

# Reduce crash loops when Docker DNS is briefly unavailable after container start.
if command -v getent >/dev/null 2>&1; then
    _db_host="${MYSQL_HOST:-mysql}"
    i=0
    while [ "$i" -lt 45 ]; do
        if getent hosts "$_db_host" >/dev/null 2>&1; then
            break
        fi
        i=$((i + 1))
        if [ "$i" -eq 1 ] || [ $((i % 10)) -eq 0 ]; then
            echo "greenhood-openemr-entrypoint: waiting for ${_db_host} to resolve (${i}/45)..." >&2
        fi
        sleep 2
    done
    if ! getent hosts "$_db_host" >/dev/null 2>&1; then
        echo "greenhood-openemr-entrypoint: WARNING: cannot resolve ${_db_host}; check Compose network and MYSQL_HOST." >&2
    fi
fi

if [ -d /var/www/localhost/htdocs/openemr ]; then
    cd /var/www/localhost/htdocs/openemr || true
fi

# openemr.sh chmods this nested path; npm often hoists oe-cda-schematron to ccdaservice/node_modules only.
_schematron_nested="./ccdaservice/node_modules/oe-schematron-service/node_modules/oe-cda-schematron"
_schematron_top="./ccdaservice/node_modules/oe-cda-schematron"
if [ ! -e "$_schematron_nested" ] && [ -e "$_schematron_top" ]; then
    mkdir -p ./ccdaservice/node_modules/oe-schematron-service/node_modules
    ln -sfn "../../oe-cda-schematron" "$_schematron_nested"
fi

if [ -f ./openemr.sh ]; then
    exec ./openemr.sh "$@"
fi

if [ -f /openemr.sh ]; then
    exec /openemr.sh "$@"
fi

echo "greenhood-openemr-entrypoint: could not find openemr.sh; inspect the parent image (docker inspect openemr/openemr:latest)." >&2
exit 1
