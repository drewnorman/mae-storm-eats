#!/usr/bin/env bash
set -euo pipefail

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
repo_root="$(CDPATH= cd -- "${script_dir}/../.." && pwd)"

cd "${repo_root}"

version="${1:-$(git rev-parse --short HEAD)}"
artifact="dist/mae-storm-eats-${version}.tar.gz"
temporary_dir="$(mktemp -d)"

cleanup() {
    rm -rf -- "${temporary_dir:?}"
}
trap cleanup EXIT

scripts/release/build-artifact.sh "${version}" >/dev/null
cp "${artifact}" "${temporary_dir}/first.tar.gz"
scripts/release/build-artifact.sh "${version}" >/dev/null

if ! cmp -s "${temporary_dir}/first.tar.gz" "${artifact}"; then
    echo "Repeated artifact builds produced different bytes." >&2
    exit 1
fi

contents="$(tar -tzf "${artifact}")"

for required_path in \
    mae-storm-eats/vendor/autoload.php \
    mae-storm-eats/vendor/timber/timber/src/Timber.php \
    mae-storm-eats/themes/mae-storm-eats/assets/dist/app.css \
    mae-storm-eats/themes/mae-storm-eats/assets/dist/app.js \
    mae-storm-eats/plugins/mae-storm-eats/mae-storm-eats.php \
    mae-storm-eats/composer.lock \
    mae-storm-eats/yarn.lock; do
    if ! grep -Fxq "${required_path}" <<< "${contents}"; then
        echo "Artifact is missing ${required_path}." >&2
        exit 1
    fi
done

if grep -Eq '(^|/)(node_modules|uploads|\.git|\.direnv|\.terraform)(/|$)|(^|/)\.env$|\.tfstate($|\.)' <<< "${contents}"; then
    echo "Artifact contains excluded development or persistent state." >&2
    exit 1
fi

tar -xzf "${artifact}" -C "${temporary_dir}"
php -r "require '${temporary_dir}/mae-storm-eats/vendor/autoload.php'; exit(class_exists('Timber\\\\Timber') ? 0 : 1);"

echo "Release artifact is complete and reproducible."
