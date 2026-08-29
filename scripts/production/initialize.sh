#!/usr/bin/env bash
set -euo pipefail

site_url="${RECIPES_SITE_URL:?RECIPES_SITE_URL is required}"
site_title="${RECIPES_SITE_TITLE:?RECIPES_SITE_TITLE is required}"
admin_user="${RECIPES_ADMIN_USER:?RECIPES_ADMIN_USER is required}"
admin_password="${RECIPES_ADMIN_PASSWORD:?RECIPES_ADMIN_PASSWORD is required}"
admin_email="${RECIPES_ADMIN_EMAIL:?RECIPES_ADMIN_EMAIL is required}"
importer_email="${RECIPES_IMPORTER_EMAIL:?RECIPES_IMPORTER_EMAIL is required}"
wordpress_path="${WORDPRESS_PATH:-/var/www/html}"

wp=(wp --allow-root --path="${wordpress_path}")

if ! "${wp[@]}" core is-installed; then
    "${wp[@]}" core install \
        --url="${site_url}" \
        --title="${site_title}" \
        --admin_user="${admin_user}" \
        --admin_password="${admin_password}" \
        --admin_email="${admin_email}" \
        --skip-email
fi

"${wp[@]}" core update-db
"${wp[@]}" option update home "${site_url}"
"${wp[@]}" option update siteurl "${site_url}"
"${wp[@]}" plugin activate mae-storm-recipes
"${wp[@]}" theme activate mae-storm-recipes

if ! "${wp[@]}" user get pinterest-importer --field=ID >/dev/null 2>&1; then
    generated_password="$(openssl rand -base64 36)"
    "${wp[@]}" user create pinterest-importer "${importer_email}" \
        --role=author --user_pass="${generated_password}" --porcelain >/dev/null
fi

"${wp[@]}" eval 'if (!class_exists("Timber\\Timber")) { fwrite(STDERR, "Timber unavailable\n"); exit(1); }'
"${wp[@]}" plugin is-active mae-storm-recipes
"${wp[@]}" theme is-active mae-storm-recipes
"${wp[@]}" user get pinterest-importer --field=ID >/dev/null

echo "WordPress initialization verified for ${site_url}."
