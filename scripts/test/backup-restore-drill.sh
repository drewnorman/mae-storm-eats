#!/usr/bin/env bash
set -euo pipefail

repo_root="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
temporary="$(mktemp -d)"
trap 'rm -rf -- "${temporary}"' EXIT
source_tree="${temporary}/source"
restore_tree="${temporary}/restored"
bin_dir="${temporary}/bin"
archive="${temporary}/uploaded.tar.gz"
mkdir -p "${source_tree}"/{uploads,runtime,releases,backup-staging} "${bin_dir}"
printf 'CREATE DATABASE wordpress;\n' >"${temporary}/database.sql"
printf 'image bytes\n' >"${source_tree}/uploads/recipe.jpg"
printf 'WORDPRESS_DB_NAME=wordpress\n' >"${source_tree}/runtime/wordpress.env"
printf 'VERSION=0.1.0\nIMAGE=example@sha256:test\n' >"${source_tree}/releases/deployed.env"

cat >"${bin_dir}/podman" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
[[ "${1:-}" == exec ]] || exit 2
shift
if [[ "${1:-}" == -i ]]; then
    shift
    [[ "${1:-}" == mae-storm-eats-db && "${2:-}" == mariadb ]] || exit 2
    [[ "${MAE_STORM_EATS_TEST_DB_FAIL:-0}" != 1 ]] || exit 1
    cat >"${MAE_STORM_EATS_TEST_DB_OUTPUT:?}"
else
    [[ "${1:-}" == mae-storm-eats-db && "${2:-}" == mariadb-dump ]] || exit 2
    cat "${MAE_STORM_EATS_TEST_DB_INPUT:?}"
fi
STUB
cat >"${bin_dir}/gcloud" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
if [[ "${1:-}" == storage && "${2:-}" == cp ]]; then
    cp "$3" "${MAE_STORM_EATS_TEST_UPLOADED_ARCHIVE:?}"
elif [[ "${1:-}" == logging && "${2:-}" == write ]]; then
    [[ " $* " == *' --monitored-resource-type=global '* ]] || exit 2
    touch "${MAE_STORM_EATS_TEST_LOG_OUTPUT:?}"
else
    exit 2
fi
STUB
chmod +x "${bin_dir}/podman" "${bin_dir}/gcloud"

PATH="${bin_dir}:${PATH}" \
MAE_STORM_EATS_SHARED_ROOT="${source_tree}" MAE_STORM_EATS_BACKUP_BUCKET=recipes-test \
WORDPRESS_DB_NAME=wordpress WORDPRESS_DB_USER=wordpress WORDPRESS_DB_PASSWORD=test \
MAE_STORM_EATS_TEST_DB_INPUT="${temporary}/database.sql" \
MAE_STORM_EATS_TEST_UPLOADED_ARCHIVE="${archive}" \
MAE_STORM_EATS_TEST_LOG_OUTPUT="${temporary}/logged" \
    "${repo_root}/scripts/production/backup.sh"
test -s "${archive}"
test -f "${temporary}/logged"
test -z "$(find "${source_tree}/backup-staging" -mindepth 1 -print -quit)"

PATH="${bin_dir}:${PATH}" \
MAE_STORM_EATS_TEST_DB_OUTPUT="${temporary}/restored.sql" \
MAE_STORM_EATS_CONFIRM_RESTORE=YES MARIADB_ROOT_PASSWORD=test \
    "${repo_root}/scripts/production/restore.sh" "${archive}" "${restore_tree}"

cmp "${temporary}/database.sql" "${temporary}/restored.sql"
cmp "${source_tree}/uploads/recipe.jpg" "${restore_tree}/uploads/recipe.jpg"
cmp "${source_tree}/runtime/wordpress.env" "${restore_tree}/runtime/wordpress.env"
cmp "${source_tree}/releases/deployed.env" "${restore_tree}/releases/deployed.env"

printf 'original image\n' >"${restore_tree}/uploads/recipe.jpg"
if PATH="${bin_dir}:${PATH}" MAE_STORM_EATS_TEST_DB_FAIL=1 \
    MAE_STORM_EATS_CONFIRM_RESTORE=YES MARIADB_ROOT_PASSWORD=test \
    "${repo_root}/scripts/production/restore.sh" "${archive}" "${restore_tree}" >/dev/null 2>&1; then
    echo "Restore replaced files after the database restore failed." >&2
    exit 1
fi
test "$(cat "${restore_tree}/uploads/recipe.jpg")" = 'original image'

mkdir -p "${temporary}/invalid"
tar -xzf "${archive}" -C "${temporary}/invalid"
rm "${temporary}/invalid/database.sql"
tar -C "${temporary}/invalid" -czf "${temporary}/invalid.tar.gz" .
if MAE_STORM_EATS_CONFIRM_RESTORE=YES "${repo_root}/scripts/production/restore.sh" \
    "${temporary}/invalid.tar.gz" "${restore_tree}" >/dev/null 2>&1; then
    echo "Restore accepted a backup without a database dump." >&2
    exit 1
fi

echo "Disposable backup, upload, logging, and restore drill passed."
