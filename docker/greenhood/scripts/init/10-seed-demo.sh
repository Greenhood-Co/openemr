#!/bin/sh
# Runs once (idempotent) — see contrib/greenhood/seed_demo_data.php
set -eu
OMR="${OPENEMR_ROOT:-/var/www/localhost/htdocs/openemr}"
# OpenEMR RootCliGuard forbids PHP CLI as UID 0; run as the web user.
su -s /bin/sh apache -c "php '${OMR}/contrib/greenhood/seed_demo_data.php'"
