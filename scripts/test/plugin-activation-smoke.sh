#!/usr/bin/env bash
set -eu

echo "plugin activation smoke: future check will activate the recipe plugin and assert CPT, taxonomies, meta, REST routes, and WP-CLI commands are registered."

if [ -d "plugins/mae-storm-recipes" ]; then
  echo "plugin activation smoke: plugin directory exists."
else
  echo "plugin activation smoke: plugin directory not present yet; skipping."
fi
