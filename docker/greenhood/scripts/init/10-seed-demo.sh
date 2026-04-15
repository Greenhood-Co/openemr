#!/bin/sh
# Runs once (idempotent) — see contrib/greenhood/seed_demo_data.php
set -eu
OMR="${OPENEMR_ROOT:-/var/www/localhost/htdocs/openemr}"
php "${OMR}/contrib/greenhood/seed_demo_data.php"
