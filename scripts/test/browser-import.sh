#!/usr/bin/env bash
set -euo pipefail

php tests/browser-import.php
node tests/extension.mjs

echo "Browser import checks passed."
