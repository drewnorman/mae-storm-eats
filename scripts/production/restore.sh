#!/usr/bin/env bash
set -euo pipefail

archive="${1:?backup archive is required}"
shared_root="${2:-/var/lib/shared}"
[[ "${RECIPES_CONFIRM_RESTORE:-}" == YES ]] || {
    echo "Set RECIPES_CONFIRM_RESTORE=YES for this destructive restore." >&2
    exit 1
}
test -s "${archive}"
stage="$(mktemp -d)"
trap 'rm -rf -- "${stage}"' EXIT
tar -xzf "${archive}" -C "${stage}"

for required in database.sql uploads runtime deployed.env; do
    test -e "${stage}/${required}" || { echo "Backup is missing ${required}." >&2; exit 1; }
done
test -s "${stage}/database.sql"

if ! podman exec -i "${RECIPES_DB_CONTAINER:-recipes-db}" mariadb --user=root \
    --password="${MARIADB_ROOT_PASSWORD:?MARIADB_ROOT_PASSWORD is required}" <"${stage}/database.sql"; then
    echo "Database restore failed; persistent files were not changed." >&2
    exit 1
fi

mkdir -p "${shared_root}/uploads" "${shared_root}/runtime" "${shared_root}/releases"
find "${shared_root}/uploads" -mindepth 1 -delete
find "${shared_root}/runtime" -mindepth 1 -delete
cp -a "${stage}/uploads/." "${shared_root}/uploads/"
cp -a "${stage}/runtime/." "${shared_root}/runtime/"
cp "${stage}/deployed.env" "${shared_root}/releases/deployed.env"
chmod 0700 "${shared_root}/runtime"
echo "Restore completed from ${archive}."
