#!/usr/bin/env bash
set -euo pipefail

url="${RECIPES_SITE_URL:?RECIPES_SITE_URL is required}"
url="${url%/}"
wp=(podman exec recipes wp --allow-root --path=/var/www/html)
response="$(mktemp)"
trap 'rm -f -- "${response}"' EXIT

"${wp[@]}" plugin is-active mae-storm-recipes
"${wp[@]}" theme is-active mae-storm-recipes
"${wp[@]}" eval 'if (!class_exists(\Timber\Timber::class)) { fwrite(STDERR, "Timber unavailable\n"); exit(1); }'

curl --fail --silent --show-error --location --max-time 20 "${url}/wp-login.php" >/dev/null

status="$(curl --silent --show-error --location --max-time 20 --output "${response}" --write-out '%{http_code}' "${url}/")"
if [[ "${status}" != 401 ]] || ! grep -Fq 'Private recipes' "${response}"; then
    echo "Private frontend did not render its protected login screen (HTTP ${status})." >&2
    exit 1
fi

status="$(curl --silent --show-error --max-time 20 --output /dev/null --write-out '%{http_code}' "${url}/wp-json/wp/v2/recipes")"
case "${status}" in
    401|403) ;;
    *) echo "Protected recipe REST route returned ${status}, expected 401 or 403." >&2; exit 1 ;;
esac
echo "Production smoke checks passed."
