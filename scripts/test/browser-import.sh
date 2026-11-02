#!/usr/bin/env bash
set -euo pipefail

php tests/browser-import.php
corepack yarn check:extension-build
node tests/extension.ts

echo "Browser import checks passed."
