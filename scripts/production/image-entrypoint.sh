#!/usr/bin/env bash
set -euo pipefail

runtime_config=/run/secrets/wordpress.env
if [[ -r "${runtime_config}" ]]; then
    set -a
    # The file is root-readable and contains only shell-safe KEY=VALUE entries.
    source "${runtime_config}"
    set +a
fi

uploads=/var/www/html/wp-content/uploads
if [[ "$(id -u)" -ne 0 || ! -d "${uploads}" || -L "${uploads}" ]]; then
    echo "The uploads mount must be a directory accessible to root before WordPress starts." >&2
    exit 1
fi

# The host creates the persistent mount as root. Repair existing directories and
# files as well, since a root-owned year directory prevents new month uploads.
# find does not follow symlinks and -xdev keeps the repair inside this mount.
find "${uploads}" -xdev \( -type d -o -type f \) \
    \( ! -user www-data -o ! -group www-data \) \
    -exec chown www-data:www-data -- {} +
find "${uploads}" -xdev -type d ! -perm -0700 -exec chmod u+rwx -- {} +

exec docker-entrypoint.sh "$@"
