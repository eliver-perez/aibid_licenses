#!/bin/sh
set -eu
umask 077
export AIBID_CONFIG=/srv/aibidlicense/shared/config.php
cd /srv/aibidlicense/current
stamp=$(date -u +%Y%m%dT%H%M%SZ)
destination=/srv/aibidlicense/backup/archives
php bin/cleanup.php
# Exclusive filenames; no retention deletion or remote transfer is performed here.
set -C
php bin/audit-verify.php > "$destination/$stamp.anchor.json"
php bin/backup.php --action=create --key-file=/srv/aibidlicense/backup/recovery.key --output="$destination/$stamp.aibidbackup" > "$destination/$stamp.receipt.json"
