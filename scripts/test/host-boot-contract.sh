#!/usr/bin/env bash
set -euo pipefail

repo_root="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
cd "${repo_root}"

bash -n tofu/startup.sh scripts/production/hydrate-secrets.sh

secret_path=/var/lib/shared/runtime/wordpress.env
for unit in deploy/quadlet/recipes-db.container deploy/quadlet/recipes.container; do
    grep -Fxq "EnvironmentFile=${secret_path}" "${unit}"
done
grep -Fxq "Environment=RECIPES_SECRET_FILE=${secret_path}" deploy/systemd/recipes-secrets.service
grep -Fq 'RECIPES_SECRET_FILE:-/var/lib/shared/runtime/wordpress.env' scripts/production/hydrate-secrets.sh

for unit in deploy/quadlet/*.container; do
    grep -Fxq 'RequiresMountsFor=/var/lib/shared' "${unit}"
    grep -Fxq 'ConditionPathExists=/var/lib/shared/releases/launch-enabled' "${unit}"
    grep -Fxq 'WantedBy=multi-user.target' "${unit}"
done
grep -Fq 'RequiresMountsFor=/var/lib/shared' deploy/systemd/recipes-secrets.service

grep -Fq 'instance/attributes/recipes-bootstrap-ref' tofu/startup.sh
grep -Fq '^[0-9a-f]{40}$' tofu/startup.sh
grep -Fq 'checkout --detach --force "${bootstrap_ref}"' tofu/startup.sh
grep -Fq 'UUID=%s %s ext4 defaults,nofail,discard 0 2' tofu/startup.sh
grep -Fq 'systemctl cat "${unit}"' tofu/startup.sh
if grep -Eq 'systemctl enable.*(recipes-db|recipes\.service|recipes-proxy|recipes-backup\.timer)' tofu/startup.sh; then
    echo 'Host startup must not enable the app or backup timer before first deployment.' >&2
    exit 1
fi

echo 'Host boot, secret path, and reboot unit contract checks passed.'
