#!/usr/bin/env bash
set -euo pipefail

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
repo_root="$(CDPATH= cd -- "${script_dir}/../.." && pwd)"
compose=(podman-compose -f compose.yaml)
plugin_file="/var/www/html/wp-content/plugins/mae-storm-eats/mae-storm-eats.php"

cd "${repo_root}"

if ! command -v podman >/dev/null 2>&1 || ! command -v podman-compose >/dev/null 2>&1; then
    echo "Podman and podman-compose are required. Enter the Nix development shell first." >&2
    exit 1
fi

if ! podman info >/dev/null 2>&1; then
    echo "Podman is installed but not usable. Start the Podman machine or user service first." >&2
    exit 1
fi

for required_file in \
    vendor/autoload.php \
    themes/mae-storm-eats/assets/dist/app.css \
    themes/mae-storm-eats/assets/dist/app.js; do
    if [ ! -s "${required_file}" ]; then
        echo "${required_file} is missing; run 'just bootstrap' first." >&2
        exit 1
    fi
done

if ! podman network exists local-proxy; then
    echo "The external Podman network 'local-proxy' is missing. Start the shared local Traefik proxy first." >&2
    exit 1
fi

"${compose[@]}" up -d

if ! "${compose[@]}" exec -T wordpress test -f "${plugin_file}"; then
    echo "Plugin mount is stale or missing; recreating the WordPress container."
    "${compose[@]}" up -d --force-recreate --no-deps wordpress
fi

if ! "${compose[@]}" exec -T wordpress test -f "${plugin_file}"; then
    echo "Mae Storm Eats is still missing from the WordPress container." >&2
    echo "Expected: ${plugin_file}" >&2
    exit 1
fi

attempt=1
while [ "${attempt}" -le 30 ]; do
    if cat "${script_dir}/bootstrap-wordpress.php" | "${compose[@]}" exec -T wordpress php /dev/stdin; then
        if [ "${MAE_STORM_EATS_DEV_DETACH:-0}" = "1" ]; then
            echo "WordPress is running at http://wordpress.mae-storm-eats.localhost"
            exit 0
        fi

        "${compose[@]}" stop
        echo "Starting WordPress at http://wordpress.mae-storm-eats.localhost"
        echo "Press Ctrl-C to stop the containers."
        exec "${compose[@]}" up
    fi

    if [ "${attempt}" -eq 30 ]; then
        break
    fi

    echo "WordPress is not ready yet; retrying (${attempt}/30)."
    sleep 2
    attempt=$((attempt + 1))
done

echo "WordPress did not become ready. Run 'just dev-logs' for container output." >&2
exit 1
