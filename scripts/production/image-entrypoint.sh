#!/usr/bin/env bash
set -euo pipefail

runtime_config=/run/secrets/wordpress.env
if [[ -r "${runtime_config}" ]]; then
    set -a
    # The file is root-readable and contains only shell-safe KEY=VALUE entries.
    source "${runtime_config}"
    set +a
fi

exec docker-entrypoint.sh "$@"
