#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
temporary="$(mktemp -d)"
trap 'rm -rf -- "${temporary}"' EXIT
mkdir -p "${temporary}/bin"

cat >"${temporary}/wordpress.env" <<'ENV'
MAE_STORM_EATS_SITE_URL=https://eats.maestorm.com
MAE_STORM_EATS_SITE_TITLE="Mae Storm Eats"
MAE_STORM_EATS_ADMIN_USER=admin
MAE_STORM_EATS_ADMIN_PASSWORD=test-password
MAE_STORM_EATS_ADMIN_EMAIL=admin@example.com
MAE_STORM_EATS_IMPORTER_EMAIL=importer@example.com
ENV

cat >"${temporary}/bin/wp" <<'MOCK'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"${WP_LOG}"
case " $* " in
    *' core is-installed '*) [[ "${WP_INSTALLED}" == 1 ]] ;;
    *' user get pinterest-importer '*) printf '1\n' ;;
esac
MOCK
chmod 0755 "${temporary}/bin/wp"

export PATH="${temporary}/bin:${PATH}"
export WORDPRESS_PATH="${temporary}/wordpress"
export MAE_STORM_EATS_RUNTIME_CONFIG="${temporary}/wordpress.env"
export MAE_STORM_EATS_SITE_TITLE='"Mae Storm Eats"'
export WP_LOG="${temporary}/wp.log"

for installed in 1 0; do
    export WP_INSTALLED="${installed}"
    : >"${WP_LOG}"
    "${repo_root}/scripts/production/initialize.sh" >/dev/null
    grep -Fqx -- "--allow-root --path=${WORDPRESS_PATH} option update blogname Mae Storm Eats" "${WP_LOG}"
    if [[ "${installed}" == 0 ]]; then
        grep -Fqx -- "--allow-root --path=${WORDPRESS_PATH} core install --url=https://eats.maestorm.com --title=Mae Storm Eats --admin_user=admin --admin_password=test-password --admin_email=admin@example.com --skip-email" "${WP_LOG}"
    fi
done

echo 'Production initialization normalizes the site title for new and existing installations.'
