#!/usr/bin/env bash
set -euo pipefail

shared_root="${RECIPES_SHARED_ROOT:-/var/lib/shared}"
bucket="${RECIPES_BACKUP_BUCKET:?RECIPES_BACKUP_BUCKET is required}"
release_file="${shared_root}/releases/deployed.env"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
stage="${shared_root}/backup-staging/${timestamp}"
archive="${shared_root}/backup-staging/recipes-${timestamp}.tar.gz"
mkdir -p "${stage}"
trap 'rm -rf -- "${stage}"' EXIT

if ! podman exec recipes-db mariadb-dump --single-transaction --databases "${WORDPRESS_DB_NAME:?}" \
    --user="${WORDPRESS_DB_USER:?}" --password="${WORDPRESS_DB_PASSWORD:?}" >"${stage}/database.sql"; then
    echo "Database dump failed; backup will not be uploaded." >&2
    exit 1
fi
test -s "${stage}/database.sql"
cp -a "${shared_root}/uploads" "${stage}/uploads"
cp -a "${shared_root}/runtime" "${stage}/runtime"
cp "${release_file}" "${stage}/deployed.env"
tar --sort=name --mtime="@0" --owner=0 --group=0 --numeric-owner -C "${stage}" -czf "${archive}" .
gcloud storage cp "${archive}" "gs://${bucket}/production/${timestamp}/"
gcloud logging write recipes-backup "backup completed ${timestamp}" --severity=INFO
rm -f -- "${archive}"
echo "Uploaded production backup ${timestamp}."
