#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
temporary="$(mktemp -d)"
trap 'rm -rf -- "${temporary}"' EXIT
mkdir -p "${temporary}/bin"

cat >"${temporary}/bin/podman" <<'MOCK'
#!/usr/bin/env bash
exit 0
MOCK
cat >"${temporary}/bin/curl" <<'MOCK'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"${MAE_STORM_EATS_SMOKE_TEST_LOG}"
url="${!#}"
output=''
while (($#)); do
    case "$1" in
        --output) output="$2"; shift 2 ;;
        *) shift ;;
    esac
done
case "${url}" in
    */wp-login.php) printf 'WordPress login'; exit 0 ;;
    https://eats.maestorm.com/)
        printf 'Private recipes' >"${output}"
        printf '401'
        ;;
    */wp-json/wp/v2/recipes)
        if [[ "${MAE_STORM_EATS_SMOKE_TEST_REST_MODE:-json}" == html ]]; then
            printf 'Private recipes' >"${output}"
            printf '401 text/html; charset=UTF-8'
        else
            printf '{"code":"mae_storm_eats_private_rest"}' >"${output}"
            printf '401 application/json; charset=UTF-8'
        fi
        ;;
    *) echo "Unexpected smoke URL: ${url}" >&2; exit 1 ;;
esac
MOCK
chmod 0755 "${temporary}/bin/podman" "${temporary}/bin/curl"
export PATH="${temporary}/bin:${PATH}"
export MAE_STORM_EATS_SITE_URL=https://eats.maestorm.com
export MAE_STORM_EATS_SMOKE_TEST_LOG="${temporary}/calls.log"

"${repo_root}/scripts/production/smoke.sh" >"${temporary}/success.log"
grep -Fq 'Production smoke checks passed.' "${temporary}/success.log"
test "$(grep -Fc -- '--resolve eats.maestorm.com:443:127.0.0.1' "${temporary}/calls.log")" -eq 3

if MAE_STORM_EATS_SMOKE_TEST_REST_MODE=html "${repo_root}/scripts/production/smoke.sh" >"${temporary}/failure.log" 2>&1; then
    echo 'Smoke accepted a 401 HTML page in place of the protected REST JSON error.' >&2
    exit 1
fi
grep -Fq 'did not return its JSON access error' "${temporary}/failure.log"
echo 'Origin HTTPS and protected REST smoke checks passed.'
