#!/usr/bin/env bash
set -euo pipefail

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
repo_root="$(CDPATH= cd -- "${script_dir}/../.." && pwd)"

cd "${repo_root}"

version="${1:-$(git rev-parse --short HEAD)}"
artifact_dir="dist"
artifact="${artifact_dir}/recipes-${version}.tar.gz"
source_date_epoch="$(git show -s --format=%ct HEAD)"
temporary_dir="$(mktemp -d)"
stage_dir="${temporary_dir}/recipes"

cleanup() {
    rm -rf -- "${temporary_dir:?}"
}
trap cleanup EXIT

if [[ ! "${version}" =~ ^[A-Za-z0-9._-]+$ ]]; then
    echo "Artifact version may contain only letters, numbers, dots, underscores, and hyphens." >&2
    exit 1
fi

for command in corepack composer git tar gzip; do
    if ! command -v "${command}" >/dev/null 2>&1; then
        echo "${command} is required. Enter the Nix development shell first." >&2
        exit 1
    fi
done

mkdir -p "${artifact_dir}" "${stage_dir}"
git archive HEAD | tar -x -C "${stage_dir}"

(
    cd "${stage_dir}"
    export XDG_CACHE_HOME="${RECIPES_CACHE_HOME:-/tmp/recipes-cache}"
    export COREPACK_HOME="${RECIPES_COREPACK_HOME:-/tmp/recipes-corepack}"
    corepack yarn install --immutable
    corepack yarn build
    composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --classmap-authoritative
    rm -rf node_modules .yarn/install-state.gz
)

for required_file in \
    "${stage_dir}/vendor/autoload.php" \
    "${stage_dir}/themes/mae-storm-recipes/assets/dist/app.css" \
    "${stage_dir}/themes/mae-storm-recipes/assets/dist/app.js"; do
    if [ ! -s "${required_file}" ]; then
        echo "Artifact staging is missing ${required_file#${stage_dir}/}." >&2
        exit 1
    fi
done

find "${stage_dir}" -exec touch -h -d "@${source_date_epoch}" {} +

tar \
    --sort=name \
    --format=posix \
    --pax-option=delete=atime,delete=ctime \
    --mtime="@${source_date_epoch}" \
    --owner=0 \
    --group=0 \
    --numeric-owner \
    -C "${temporary_dir}" \
    -cf - recipes \
    | gzip -n > "${artifact}"

echo "${artifact}"
