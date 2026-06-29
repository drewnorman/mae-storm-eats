#!/usr/bin/env bash
set -eu

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
repo_root="$(CDPATH= cd -- "${script_dir}/../.." && pwd)"
compose=(podman-compose -f compose.yaml)
plugin_file="/var/www/html/wp-content/plugins/mae-storm-recipes/mae-storm-recipes.php"

cd "${repo_root}"

if ! command -v podman >/dev/null 2>&1 || ! command -v podman-compose >/dev/null 2>&1; then
    echo "Podman and podman-compose are required. Enter the Nix development shell first." >&2
    exit 1
fi

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
    echo "Mae Storm Recipes is still missing from the WordPress container." >&2
    echo "Expected: ${plugin_file}" >&2
    exit 1
fi

attempt=1
while [ "${attempt}" -le 15 ]; do
    if "${compose[@]}" exec -T wordpress php /dev/stdin < "${script_dir}/bootstrap-wordpress.php"; then
        "${compose[@]}" stop
        echo "Starting WordPress at http://wordpress.recipes.localhost"
        echo "Press Ctrl-C to stop the containers."
        exec "${compose[@]}" up
    fi

    if [ "${attempt}" -eq 15 ]; then
        break
    fi

    echo "WordPress is not ready yet; retrying (${attempt}/15)."
    sleep 2
    attempt=$((attempt + 1))
done

echo "WordPress did not become ready. Run 'just dev-logs' for container output." >&2
exit 1
