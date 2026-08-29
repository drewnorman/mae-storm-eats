#!/usr/bin/env bash
set -euo pipefail

repo_root="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
temporary="$(mktemp -d)"
trap 'rm -rf -- "${temporary}"' EXIT
source_tree="${temporary}/source"
restore_tree="${temporary}/restored"
bin_dir="${temporary}/bin"
mkdir -p "${source_tree}"/{uploads,runtime} "${bin_dir}"
printf 'CREATE DATABASE wordpress;\n' >"${source_tree}/database.sql"
printf 'image bytes\n' >"${source_tree}/uploads/recipe.jpg"
printf 'WORDPRESS_DB_NAME=wordpress\n' >"${source_tree}/runtime/wordpress.env"
printf 'VERSION=0.1.0\nIMAGE=example@sha256:test\n' >"${source_tree}/deployed.env"
tar -C "${source_tree}" -czf "${temporary}/backup.tar.gz" .

printf '%s\n' '#!/usr/bin/env bash' 'cat >"${RECIPES_TEST_DB_OUTPUT:?}"' >"${bin_dir}/mariadb"
chmod +x "${bin_dir}/mariadb"

PATH="${bin_dir}:${PATH}" \
RECIPES_TEST_DB_OUTPUT="${temporary}/restored.sql" \
RECIPES_CONFIRM_RESTORE=YES \
WORDPRESS_DB_HOST=db:3306 WORDPRESS_DB_USER=wordpress WORDPRESS_DB_PASSWORD=test \
    "${repo_root}/scripts/production/restore.sh" "${temporary}/backup.tar.gz" "${restore_tree}"

cmp "${source_tree}/database.sql" "${temporary}/restored.sql"
cmp "${source_tree}/uploads/recipe.jpg" "${restore_tree}/uploads/recipe.jpg"
cmp "${source_tree}/runtime/wordpress.env" "${restore_tree}/runtime/wordpress.env"
cmp "${source_tree}/deployed.env" "${restore_tree}/releases/deployed.env"

rm "${source_tree}/database.sql"
tar -C "${source_tree}" -czf "${temporary}/invalid.tar.gz" .
if RECIPES_CONFIRM_RESTORE=YES "${repo_root}/scripts/production/restore.sh" \
    "${temporary}/invalid.tar.gz" "${restore_tree}" >/dev/null 2>&1; then
    echo "Restore accepted a backup without a database dump." >&2
    exit 1
fi

echo "Disposable database, uploads, config, and release restore drill passed."
