#!/usr/bin/env bash
set -euo pipefail

status=0

while IFS= read -r reference; do
    if [[ ! "${reference}" =~ :[0-9]+\.[0-9]+\.[0-9]+[^@]*@sha256:[0-9a-f]{64}$ ]]; then
        echo "Container image is not pinned to a patch tag and digest: ${reference}" >&2
        status=1
    fi
done < <(sed -nE 's/^[[:space:]]+image:[[:space:]]+([^[:space:]]+).*$/\1/p' compose.yaml)

while IFS= read -r reference; do
    if [[ ! "${reference}" =~ :[0-9]+\.[0-9]+\.[0-9]+[^@]*@sha256:[0-9a-f]{64}$ ]]; then
        echo "Containerfile base is not pinned to a patch tag and digest: ${reference}" >&2
        status=1
    fi
done < <(sed -nE 's/^(ARG WORDPRESS_IMAGE=|FROM )([^$[:space:]]+:[^[:space:]]+@sha256:[0-9a-f]{64}).*$/\2/p' Containerfile)

while IFS= read -r reference; do
    if [[ ! "${reference}" =~ :[0-9]+\.[0-9]+\.[0-9]+[^@]*@sha256:[0-9a-f]{64}$ ]]; then
        echo "Infrastructure image is not pinned to a patch tag and digest: ${reference}" >&2
        status=1
    fi
done < <(sed -nE 's/^Image=([^g][^[:space:]]+@sha256:[0-9a-f]{64})$/\1/p' deploy/quadlet/*.container)

if [ "${status}" -ne 0 ]; then
    exit "${status}"
fi

echo "Container image pins are valid."
