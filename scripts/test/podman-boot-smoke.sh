#!/usr/bin/env bash
set -euo pipefail

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
repo_root="$(CDPATH= cd -- "${script_dir}/../.." && pwd)"
compose=(podman-compose -f compose.yaml)
created_proxy_network=0

cd "${repo_root}"

export COMPOSE_PROJECT_NAME="mae-storm-eats-smoke-$$"
export MAE_STORM_EATS_TRAEFIK_ENABLE=false

cleanup() {
    "${compose[@]}" down -v >/dev/null 2>&1 || true

    if [ "${created_proxy_network}" -eq 1 ]; then
        podman network rm local-proxy >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

just bootstrap

if ! podman network exists local-proxy; then
    podman network create local-proxy >/dev/null
    created_proxy_network=1
fi

MAE_STORM_EATS_DEV_DETACH=1 just dev-up
MAE_STORM_EATS_DEV_DETACH=1 just dev-up

verification="$(cat "${script_dir}/verify-wordpress.php" | "${compose[@]}" exec -T wordpress php /dev/stdin)"
grep -Fq 'WordPress development stack verification passed.' <<< "${verification}"
echo "${verification}"

rendered_config="$("${compose[@]}" config)"
grep -Fq 'traefik.http.routers.mae-storm-eats-wordpress.rule' <<< "${rendered_config}"
grep -Fq 'local-proxy' <<< "${rendered_config}"

echo "Podman boot smoke passed."
