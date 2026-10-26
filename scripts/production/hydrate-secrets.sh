#!/usr/bin/env bash
set -euo pipefail

project="${GCP_PROJECT:-$(curl -fsS -H 'Metadata-Flavor: Google' http://metadata.google.internal/computeMetadata/v1/project/project-id)}"
secret="${MAE_STORM_EATS_SECRET_NAME:-mae-storm-eats-production}"
destination="${MAE_STORM_EATS_SECRET_FILE:-/var/lib/shared/runtime/wordpress.env}"
temporary="$(mktemp)"
trap 'rm -f -- "${temporary}"' EXIT

gcloud secrets versions access latest --project="${project}" --secret="${secret}" >"${temporary}"
chmod 0600 "${temporary}"
chown root:root "${temporary}"
required=(WORDPRESS_DB_HOST WORDPRESS_DB_NAME WORDPRESS_DB_USER WORDPRESS_DB_PASSWORD MARIADB_DATABASE MARIADB_USER MARIADB_PASSWORD MARIADB_ROOT_PASSWORD MAE_STORM_EATS_ADMIN_USER MAE_STORM_EATS_ADMIN_PASSWORD MAE_STORM_EATS_ADMIN_EMAIL MAE_STORM_EATS_IMPORTER_EMAIL MAE_STORM_EATS_SITE_URL MAE_STORM_EATS_SITE_TITLE MAE_STORM_EATS_BACKUP_BUCKET MAE_STORM_EATS_SHARED_PASSWORD AUTH_KEY SECURE_AUTH_KEY LOGGED_IN_KEY NONCE_KEY AUTH_SALT SECURE_AUTH_SALT LOGGED_IN_SALT NONCE_SALT)
for name in "${required[@]}"; do
    if ! grep -Eq "^${name}=.+" "${temporary}"; then
        echo "Secret bundle is missing ${name}." >&2
        exit 1
    fi
done

install -D -o root -g root -m 0600 "${temporary}" "${destination}"
echo "Production secret bundle hydrated with restricted permissions."
