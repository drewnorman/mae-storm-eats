#!/usr/bin/env bash
set -euo pipefail

repo_root="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
cd "${repo_root}"

outdir="${1:-extension}"
corepack yarn esbuild \
    extension/src/background.ts \
    extension/src/collector.ts \
    extension/src/connection.ts \
    extension/src/popup.ts \
    --target=firefox128 \
    --outdir="${outdir}"
