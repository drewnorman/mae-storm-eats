#!/usr/bin/env bash
set -euo pipefail

status=0

while IFS= read -r reference; do
    if [[ ! "${reference}" =~ :[0-9]+\.[0-9]+\.[0-9]+[^@]*@sha256:[0-9a-f]{64}$ ]]; then
        echo "Container image is not pinned to a patch tag and digest: ${reference}" >&2
        status=1
    fi
done < <(sed -nE 's/^[[:space:]]+image:[[:space:]]+([^[:space:]]+).*$/\1/p' compose.yaml)

if [ "${status}" -ne 0 ]; then
    exit "${status}"
fi

echo "Container image pins are valid."
