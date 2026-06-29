#!/usr/bin/env bash
set -eu

echo "protected routes smoke: future check will verify password gate behavior for recipe pages, REST routes, feeds, media attachment pages, and search."

if [ -n "${RECIPES_BASE_URL:-}" ]; then
  echo "protected routes smoke: RECIPES_BASE_URL is set, but live HTTP checks are not implemented in this scaffold."
else
  echo "protected routes smoke: RECIPES_BASE_URL not set; skipping live route checks."
fi
