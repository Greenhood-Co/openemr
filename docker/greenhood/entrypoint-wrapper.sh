#!/bin/sh
# Chains to the official OpenEMR image entrypoint and optionally patches the
# initial admin display name after auto-install (OE_ADMIN_DISPLAY_NAME).

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

if [ -d /var/www/localhost/htdocs/openemr ]; then
    cd /var/www/localhost/htdocs/openemr || true
fi

if [ -f ./openemr.sh ]; then
    exec ./openemr.sh "$@"
fi

if [ -f /openemr.sh ]; then
    exec /openemr.sh "$@"
fi

echo "greenhood-openemr-entrypoint: could not find openemr.sh; inspect the parent image (docker inspect openemr/openemr:latest)." >&2
exit 1
