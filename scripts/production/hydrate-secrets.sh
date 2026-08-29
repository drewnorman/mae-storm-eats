#!/usr/bin/env bash
set -euo pipefail

project="${GCP_PROJECT:-$(curl -fsS -H 'Metadata-Flavor: Google' http://metadata.google.internal/computeMetadata/v1/project/project-id)}"
secret="${RECIPES_SECRET_NAME:-recipes-production}"
destination="${RECIPES_SECRET_FILE:-/run/recipes/wordpress.env}"
temporary="$(mktemp)"
trap 'rm -f -- "${temporary}"' EXIT

gcloud secrets versions access latest --project="${project}" --secret="${secret}" >"${temporary}"
chmod 0600 "${temporary}"
chown root:root "${temporary}"
install -D -o root -g root -m 0600 "${temporary}" "${destination}"

required=(WORDPRESS_DB_HOST WORDPRESS_DB_NAME WORDPRESS_DB_USER WORDPRESS_DB_PASSWORD MARIADB_DATABASE MARIADB_USER MARIADB_PASSWORD MARIADB_ROOT_PASSWORD RECIPES_ADMIN_USER RECIPES_ADMIN_PASSWORD RECIPES_ADMIN_EMAIL RECIPES_IMPORTER_EMAIL RECIPES_SITE_URL RECIPES_SITE_TITLE RECIPES_BACKUP_BUCKET MAE_STORM_RECIPES_SHARED_PASSWORD AUTH_KEY SECURE_AUTH_KEY LOGGED_IN_KEY NONCE_KEY AUTH_SALT SECURE_AUTH_SALT LOGGED_IN_SALT NONCE_SALT)
for name in "${required[@]}"; do
    if ! grep -Eq "^${name}=.+" "${destination}"; then
        echo "Secret bundle is missing ${name}." >&2
        exit 1
    fi
done

echo "Production secret bundle hydrated with restricted permissions."
