#!/usr/bin/env bash
set -eu

echo "backup restore drill: future check will restore DB, uploads, candidate artifacts, and runtime config into a clean local environment."

for path in "fixtures" "plugins/mae-storm-recipes" "themes/mae-storm-recipes" "docs/service-contract.md"; do
  if [ ! -e "${path}" ]; then
    echo "backup restore drill: missing required restore input ${path}." >&2
    exit 1
  fi
done

echo "backup restore drill: app restore inputs are present."
