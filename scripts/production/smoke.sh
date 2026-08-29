#!/usr/bin/env bash
set -euo pipefail

url="${RECIPES_SITE_URL:?RECIPES_SITE_URL is required}"
curl --fail --silent --show-error --location "${url}/wp-login.php" >/dev/null
status="$(curl --silent --output /dev/null --write-out '%{http_code}' "${url}/wp-json/wp/v2/recipes")"
case "${status}" in
    401|403) ;;
    *) echo "Protected recipe REST route returned ${status}, expected 401 or 403." >&2; exit 1 ;;
esac
echo "Production smoke checks passed."
