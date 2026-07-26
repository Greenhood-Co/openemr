#!/bin/sh
# Runs once (idempotent) when TRAINING_ACCOUNT_PASSWORD is set — see provision_training_users.php
set -eu
if [ -z "${TRAINING_ACCOUNT_PASSWORD:-}" ]; then
    echo "greenhood init: TRAINING_ACCOUNT_PASSWORD unset; skipping user provisioning." >&2
    exit 0
fi
OMR="${OPENEMR_ROOT:-/var/www/localhost/htdocs/openemr}"
# OpenEMR RootCliGuard forbids PHP CLI as UID 0; run as the web user.
su -s /bin/sh apache -c "php '${OMR}/contrib/greenhood/provision_training_users.php'"
