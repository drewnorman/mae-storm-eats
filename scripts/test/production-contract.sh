#!/usr/bin/env bash
set -euo pipefail

version="$(sed 's/[[:space:]].*//' VERSION)"
[[ "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]
test "$(node -p "require('./package.json').version")" = "${version}"
test "$(node -p "require('./extension/manifest.json').version")" = "${version}"
grep -Fq "Version: ${version}" plugins/mae-storm-eats/mae-storm-eats.php
grep -Fq "MAE_STORM_EATS_VERSION', '${version}'" plugins/mae-storm-eats/mae-storm-eats.php
grep -Fq "Version: ${version}" themes/mae-storm-eats/style.css
grep -Fq -- "--mae-storm-eats-release: \"${version}\"" themes/mae-storm-eats/style.css

for script in scripts/production/*.sh; do bash -n "${script}"; done
for unit in deploy/quadlet/* deploy/systemd/*; do test -s "${unit}"; done
grep -Fq '/wp-login.php' Containerfile
grep -Fq '/wp-login.php' deploy/quadlet/mae-storm-eats.container
grep -Fq '/wp-login.php' scripts/production/smoke.sh

mode="$(stat -c '%a' scripts/production/hydrate-secrets.sh)"
test "${mode}" = 755

scripts/test/backup-restore-drill.sh
scripts/test/host-boot-contract.sh
scripts/test/production-deploy.sh
echo "Production release and runtime contract checks passed."
