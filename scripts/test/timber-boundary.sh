#!/usr/bin/env bash
set -euo pipefail

presentation_files=(
    plugins/mae-storm-eats/src/RecipeView.php
    themes/mae-storm-eats/templates
)

if rg --pcre2 -n --glob '*.php' '(?<!::)\b(get_post_meta|get_posts|get_terms|get_the_title|get_the_excerpt|wp_get_attachment_image_url)\s*\(|new[[:space:]]+\\WP_Query\b' "${presentation_files[@]}"; then
    echo "Presentation code must retrieve WordPress objects through Timber." >&2
    exit 1
fi

echo "Timber presentation boundary checks passed."
