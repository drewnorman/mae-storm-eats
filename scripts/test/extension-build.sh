#!/usr/bin/env bash
set -euo pipefail

repo_root="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
cd "${repo_root}"

temporary_dir="$(mktemp -d)"
trap 'rm -rf -- "${temporary_dir}"' EXIT

scripts/compile-extension.sh "${temporary_dir}"
for script in background collector connection popup; do
    if ! cmp -s "${temporary_dir}/${script}.js" "extension/${script}.js"; then
        echo "extension/${script}.js is out of date; run 'corepack yarn build:extension'." >&2
        exit 1
    fi
done
