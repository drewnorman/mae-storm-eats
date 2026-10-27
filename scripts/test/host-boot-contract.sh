#!/usr/bin/env bash
set -euo pipefail

repo_root="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
cd "${repo_root}"

bash -n tofu/startup.sh scripts/production/hydrate-secrets.sh

secret_path=/var/lib/shared/runtime/wordpress.env
for unit in deploy/quadlet/mae-storm-eats-db.container deploy/quadlet/mae-storm-eats.container; do
    grep -Fxq "EnvironmentFile=${secret_path}" "${unit}"
done
grep -Fxq "Environment=MAE_STORM_EATS_SECRET_FILE=${secret_path}" deploy/systemd/mae-storm-eats-secrets.service
grep -Fq 'MAE_STORM_EATS_SECRET_FILE:-/var/lib/shared/runtime/wordpress.env' scripts/production/hydrate-secrets.sh

for unit in deploy/quadlet/*.container; do
    grep -Fxq 'RequiresMountsFor=/var/lib/shared' "${unit}"
    grep -Fxq 'ConditionPathExists=/var/lib/shared/releases/launch-enabled' "${unit}"
    grep -Fxq 'WantedBy=multi-user.target' "${unit}"
done
grep -Fq 'RequiresMountsFor=/var/lib/shared' deploy/systemd/mae-storm-eats-secrets.service

grep -Fq 'instance/attributes/mae-storm-eats-bootstrap-ref' tofu/startup.sh
grep -Fq '^[0-9a-f]{40}$' tofu/startup.sh
grep -Fq 'checkout --detach --force "${bootstrap_ref}"' tofu/startup.sh
grep -Fq 'archive "${ops_ref}" scripts/production' tofu/startup.sh
grep -Fq 'UUID=%s %s ext4 defaults,nofail,discard 0 2' tofu/startup.sh
grep -Fq 'systemctl cat "${unit}"' tofu/startup.sh
if grep -Eq 'systemctl enable.*(mae-storm-eats-db|mae-storm-eats\.service|mae-storm-eats-proxy|mae-storm-eats-backup\.timer)' tofu/startup.sh; then
    echo 'Host startup must not enable the app or backup timer before first deployment.' >&2
    exit 1
fi

temporary="$(mktemp -d)"
trap 'rm -rf -- "${temporary}"' EXIT
bootstrap="$(printf 'b%.0s' {1..40})"
release="$(printf 'a%.0s' {1..40})"
metadata="${temporary}/deployed.env"
test "$(bash tofu/startup.sh --select-ops-ref "${bootstrap}" "${metadata}")" = "${bootstrap}"
printf 'VERSION=0.2.7\nAPP_SHA=%s\n' "${release}" >"${metadata}"
test "$(bash tofu/startup.sh --select-ops-ref "${bootstrap}" "${metadata}")" = "${release}"
printf 'VERSION=0.2.7\nAPP_SHA=invalid\n' >"${metadata}"
if bash tofu/startup.sh --select-ops-ref "${bootstrap}" "${metadata}" >/dev/null 2>&1; then
    echo 'Host boot accepted invalid deployed APP_SHA.' >&2
    exit 1
fi
printf 'APP_SHA=%s\nAPP_SHA=%s\n' "${release}" "${release}" >"${metadata}"
if bash tofu/startup.sh --select-ops-ref "${bootstrap}" "${metadata}" >/dev/null 2>&1; then
    echo 'Host boot accepted duplicate deployed APP_SHA.' >&2
    exit 1
fi

echo 'Host boot, secret path, and reboot unit contract checks passed.'
