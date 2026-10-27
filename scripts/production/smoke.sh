#!/usr/bin/env bash
set -euo pipefail

url="${MAE_STORM_EATS_SITE_URL:?MAE_STORM_EATS_SITE_URL is required}"
url="${url%/}"
if [[ ! "${url}" =~ ^https://([A-Za-z0-9.-]+)$ ]]; then
    echo "MAE_STORM_EATS_SITE_URL must be a bare HTTPS hostname for origin smoke checks." >&2
    exit 1
fi
site_host="${BASH_REMATCH[1]}"
origin=(curl --resolve "${site_host}:443:127.0.0.1" --silent --show-error --max-time 20)
wp=(podman exec mae-storm-eats wp --allow-root --path=/var/www/html)
response="$(mktemp)"
trap 'rm -f -- "${response}"' EXIT

"${wp[@]}" plugin is-active mae-storm-eats
"${wp[@]}" theme is-active mae-storm-eats
"${wp[@]}" eval 'if (!class_exists(\Timber\Timber::class)) { fwrite(STDERR, "Timber unavailable\n"); exit(1); }'

"${origin[@]}" --fail --location "${url}/wp-login.php" >/dev/null

status="$("${origin[@]}" --location --output "${response}" --write-out '%{http_code}' "${url}/")"
if [[ "${status}" != 401 ]] || ! grep -Fq 'Private recipes' "${response}"; then
    echo "Private frontend did not render its protected login screen (HTTP ${status})." >&2
    exit 1
fi

rest_result="$("${origin[@]}" --output "${response}" --write-out '%{http_code} %{content_type}' "${url}/wp-json/wp/v2/recipes")"
rest_status="${rest_result%% *}"
rest_content_type="${rest_result#* }"
if [[ "${rest_status}" != 401 || "${rest_content_type,,}" != application/json* ]] \
    || ! grep -Eq '"code"[[:space:]]*:[[:space:]]*"mae_storm_eats_private_rest"' "${response}"; then
    echo "Protected recipe REST route did not return its JSON access error (HTTP ${rest_status}, ${rest_content_type})." >&2
    exit 1
fi
echo "Production smoke checks passed."
