#!/usr/bin/env bash
set -euo pipefail

php tests/browser-import.php
node tests/extension.mjs

while IFS= read -r file; do
  php -l "$file" >/dev/null
done < <(find plugins/mae-storm-recipes -name '*.php' -type f | sort)

echo "Browser import syntax checks passed."
