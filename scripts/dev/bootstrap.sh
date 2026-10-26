#!/usr/bin/env bash
set -euo pipefail

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
repo_root="$(CDPATH= cd -- "${script_dir}/../.." && pwd)"

cd "${repo_root}"

for command in node corepack composer git; do
    if ! command -v "${command}" >/dev/null 2>&1; then
        echo "${command} is required. Enter the Nix development shell first." >&2
        exit 1
    fi
done

export XDG_CACHE_HOME="${MAE_STORM_EATS_CACHE_HOME:-/tmp/mae-storm-eats-cache}"
export COREPACK_HOME="${MAE_STORM_EATS_COREPACK_HOME:-/tmp/mae-storm-eats-corepack}"

mkdir -p config/dev/uploads
corepack yarn install --immutable
composer install --no-interaction --prefer-dist
corepack yarn build
git config core.hooksPath .githooks

for asset in \
    themes/mae-storm-eats/assets/dist/app.css \
    themes/mae-storm-eats/assets/dist/app.js; do
    if [ ! -s "${asset}" ]; then
        echo "Bootstrap did not create ${asset}." >&2
        exit 1
    fi
done

echo "Bootstrap complete. Run 'just dev-up' to start WordPress."
