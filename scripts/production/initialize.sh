#!/usr/bin/env bash
set -euo pipefail

site_url="${MAE_STORM_EATS_SITE_URL:?MAE_STORM_EATS_SITE_URL is required}"
site_title="${MAE_STORM_EATS_SITE_TITLE:?MAE_STORM_EATS_SITE_TITLE is required}"
admin_user="${MAE_STORM_EATS_ADMIN_USER:?MAE_STORM_EATS_ADMIN_USER is required}"
admin_password="${MAE_STORM_EATS_ADMIN_PASSWORD:?MAE_STORM_EATS_ADMIN_PASSWORD is required}"
admin_email="${MAE_STORM_EATS_ADMIN_EMAIL:?MAE_STORM_EATS_ADMIN_EMAIL is required}"
importer_email="${MAE_STORM_EATS_IMPORTER_EMAIL:?MAE_STORM_EATS_IMPORTER_EMAIL is required}"
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
"${wp[@]}" plugin activate mae-storm-eats
"${wp[@]}" theme activate mae-storm-eats

"${wp[@]}" eval 'if (!get_role("recipe_importer")) { fwrite(STDERR, "Recipe Importer role unavailable\n"); exit(1); }'

if ! "${wp[@]}" user get pinterest-importer --field=ID >/dev/null 2>&1; then
    generated_password="$(openssl rand -base64 36)"
    "${wp[@]}" user create pinterest-importer "${importer_email}" \
        --role=recipe_importer --user_pass="${generated_password}" --porcelain >/dev/null
fi
"${wp[@]}" user set-role pinterest-importer recipe_importer

"${wp[@]}" eval 'if (!class_exists(\Timber\Timber::class)) { fwrite(STDERR, "Timber unavailable\n"); exit(1); }'
"${wp[@]}" plugin is-active mae-storm-eats
"${wp[@]}" theme is-active mae-storm-eats
"${wp[@]}" eval '$user = get_user_by("login", "pinterest-importer"); if (!$user || $user->roles !== ["recipe_importer"] || !user_can($user, "mae_storm_eats_import_recipes") || user_can($user, "publish_posts")) { fwrite(STDERR, "Pinterest importer privileges are incorrect\n"); exit(1); }'

echo "WordPress initialization verified for ${site_url}."
