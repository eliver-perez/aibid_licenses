#!/bin/sh
set -eu
umask 077
export AIBID_CONFIG=/srv/aidexlicense/shared/config.php
cd /srv/aidexlicense/current
stamp=$(date -u +%Y%m%dT%H%M%SZ)
destination=/srv/aidexlicense/backup/archives
php bin/cleanup.php
# Exclusive filenames; no retention deletion or remote transfer is performed here.
set -C
php bin/audit-verify.php > "$destination/$stamp.anchor.json"
php bin/backup.php --action=create --key-file=/srv/aidexlicense/backup/recovery.key --output="$destination/$stamp.aibidbackup" > "$destination/$stamp.receipt.json"
