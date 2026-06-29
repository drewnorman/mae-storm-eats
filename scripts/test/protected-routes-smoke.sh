#!/usr/bin/env bash
set -eu

echo "protected routes smoke: future check will verify password gate behavior for recipe pages, REST routes, feeds, media attachment pages, and search."

if [ -n "${RECIPES_BASE_URL:-}" ]; then
  status="$(curl -sS -o /dev/null -w "%{http_code}" "${RECIPES_BASE_URL%/}/wp-json/wp/v2/recipes")"

  if [ "${status}" = "401" ] || [ "${status}" = "403" ]; then
    echo "protected routes smoke: recipe REST route is protected."
  else
    echo "protected routes smoke: expected protected REST route, got HTTP ${status}." >&2
    exit 1
  fi
else
  echo "protected routes smoke: RECIPES_BASE_URL not set; skipping live route checks."
fi
