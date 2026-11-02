#!/usr/bin/env bash
set -euo pipefail

image="${1:?production image is required}"
engine="${MAE_STORM_EATS_CONTAINER_ENGINE:-docker}"
uploads=/var/www/html/wp-content/uploads
volume="$("${engine}" volume create "mae-storm-eats-uploads-check-$$")"
trap '"${engine}" volume rm "${volume}" >/dev/null 2>&1 || true' EXIT

"${engine}" run --rm --volume "${volume}:${uploads}" --entrypoint sh "${image}" -eu -c '
    mkdir -p /var/www/html/wp-content/uploads/2026
    printf "existing image bytes\n" > /var/www/html/wp-content/uploads/2026/existing.jpg
    chown -R root:root /var/www/html/wp-content/uploads
    chmod 0755 /var/www/html/wp-content/uploads /var/www/html/wp-content/uploads/2026
    chmod 0644 /var/www/html/wp-content/uploads/2026/existing.jpg
'

"${engine}" run --rm --volume "${volume}:${uploads}" "${image}" sh -eu -c '
    test "$(stat -c %u:%g /var/www/html/wp-content/uploads)" = 33:33
    test "$(stat -c %u:%g /var/www/html/wp-content/uploads/2026)" = 33:33
    test "$(cat /var/www/html/wp-content/uploads/2026/existing.jpg)" = "existing image bytes"
    su -s /bin/sh www-data -c '\''
        mkdir -p /var/www/html/wp-content/uploads/2026/11
        printf "probe\n" > /var/www/html/wp-content/uploads/2026/11/probe.txt
        rm /var/www/html/wp-content/uploads/2026/11/probe.txt
    '\''
'

"${engine}" run --rm --volume "${volume}:${uploads}" --entrypoint chown "${image}" \
    -R root:root "${uploads}"
if "${engine}" run --rm --volume "${volume}:${uploads}:ro" "${image}" true >/dev/null 2>&1; then
    echo 'Production image started even though uploads ownership could not be repaired.' >&2
    exit 1
fi

echo 'Production image repairs root-owned uploads and preserves existing files.'
