#!/usr/bin/env bash
set -eu

echo "podman boot smoke: future check will start the local WordPress/MariaDB stack, activate the recipe plugin/theme, import fixtures, and render private recipe views."

if command -v podman >/dev/null 2>&1; then
  if podman --version >/dev/null 2>&1; then
    echo "podman boot smoke: podman CLI is available."
  else
    echo "podman boot smoke: podman CLI exists but is not usable in this environment; skipping until local stack exists."
  fi
else
  echo "podman boot smoke: podman CLI not found; skipping until local stack exists."
fi
