#!/usr/bin/env bash
set -euo pipefail

repo_root="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
temporary="$(mktemp -d)"
trap 'rm -rf -- "${temporary}"' EXIT
mkdir -p "${temporary}/shared/runtime" "${temporary}/bin" "${temporary}/ops"
cp "${repo_root}/deploy/quadlet/mae-storm-eats.container" "${temporary}/mae-storm-eats.container"
printf 'MAE_STORM_EATS_SITE_URL=https://eats.maestorm.com\n' >"${temporary}/shared/runtime/wordpress.env"

cat >"${temporary}/bin/systemctl" <<'MOCK'
#!/usr/bin/env bash
printf 'systemctl %s\n' "$*" >>"${MAE_STORM_EATS_TEST_LOG}"
MOCK
cat >"${temporary}/bin/podman" <<'MOCK'
#!/usr/bin/env bash
printf 'podman %s\n' "$*" >>"${MAE_STORM_EATS_TEST_LOG}"
if [[ "$*" == image\ inspect* ]]; then printf '3%.0s' {1..40}; printf '\n'; fi
MOCK
cat >"${temporary}/ops/backup" <<'MOCK'
#!/usr/bin/env bash
printf 'backup\n' >>"${MAE_STORM_EATS_TEST_LOG}"
MOCK
cat >"${temporary}/ops/smoke" <<'MOCK'
#!/usr/bin/env bash
printf 'smoke\n' >>"${MAE_STORM_EATS_TEST_LOG}"
[[ "${MAE_STORM_EATS_TEST_FAIL_SMOKE:-0}" != 1 ]]
MOCK
chmod +x "${temporary}/bin/"* "${temporary}/ops/"*

export PATH="${temporary}/bin:${PATH}"
export MAE_STORM_EATS_SHARED_ROOT="${temporary}/shared"
export MAE_STORM_EATS_QUADLET_PATH="${temporary}/mae-storm-eats.container"
export MAE_STORM_EATS_BIN_DIR="${temporary}/ops"
export MAE_STORM_EATS_TEST_LOG="${temporary}/calls.log"
image_one="ghcr.io/drewnorman/mae-storm-eats@sha256:$(printf '1%.0s' {1..64})"
image_two="ghcr.io/drewnorman/mae-storm-eats@sha256:$(printf '2%.0s' {1..64})"

if MAE_STORM_EATS_TEST_FAIL_SMOKE=1 "${repo_root}/scripts/production/deploy.sh" \
    0.1.0 "${image_one}" test-infra-sha >/dev/null 2>&1; then
    echo "First deployment accepted a failing smoke check." >&2
    exit 1
fi
test ! -e "${temporary}/shared/releases/launch-enabled"
test ! -e "${temporary}/shared/releases/deployed.env"
grep -Fxq 'Image=ghcr.io/drewnorman/mae-storm-eats:0.1.0' "${temporary}/mae-storm-eats.container"
test "$(grep -xc backup "${MAE_STORM_EATS_TEST_LOG}" || true)" -eq 0

"${repo_root}/scripts/production/deploy.sh" 0.1.0 "${image_one}" test-infra-sha
test -e "${temporary}/shared/releases/launch-enabled"
grep -Fxq "Image=${image_one}" "${temporary}/mae-storm-eats.container"
grep -Fxq 'VERSION=0.1.0' "${temporary}/shared/releases/deployed.env"
grep -Eq '^APP_SHA=[0-9a-f]{40}$' "${temporary}/shared/releases/deployed.env"
test "$(grep -xc backup "${MAE_STORM_EATS_TEST_LOG}")" -eq 1

if MAE_STORM_EATS_TEST_FAIL_SMOKE=1 "${repo_root}/scripts/production/deploy.sh" \
    0.1.1 "${image_two}" test-infra-sha >/dev/null 2>&1; then
    echo "Deployment accepted a failing smoke check." >&2
    exit 1
fi
grep -Fxq "Image=${image_one}" "${temporary}/mae-storm-eats.container"
grep -Fxq 'VERSION=0.1.0' "${temporary}/shared/releases/deployed.env"
test "$(grep -xc backup "${MAE_STORM_EATS_TEST_LOG}")" -eq 2

"${repo_root}/scripts/production/deploy.sh" 0.1.1 "${image_two}" test-infra-sha
grep -Fxq "Image=${image_two}" "${temporary}/mae-storm-eats.container"
grep -Fxq 'VERSION=0.1.1' "${temporary}/shared/releases/deployed.env"
test "$(grep -xc backup "${MAE_STORM_EATS_TEST_LOG}")" -eq 3
echo "First deployment, upgrade backup, and image rollback checks passed."
