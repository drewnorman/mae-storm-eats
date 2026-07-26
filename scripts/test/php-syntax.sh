#!/usr/bin/env bash
set -euo pipefail

while IFS= read -r file; do
    php -l "${file}" >/dev/null
done < <(find plugins themes scripts tests -name '*.php' -type f | sort)

echo "PHP syntax checks passed."
