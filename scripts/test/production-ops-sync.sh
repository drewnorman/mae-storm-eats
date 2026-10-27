#!/usr/bin/env bash
set -euo pipefail

repo_root="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
temporary="$(mktemp -d)"
trap 'rm -rf -- "${temporary}"' EXIT
source_root="${temporary}/source"
remote="${temporary}/remote.git"
mkdir -p "${source_root}/scripts/production" "${temporary}/bin" "${temporary}/host/bin"
git init -q "${source_root}"
git init --bare -q "${remote}"
git -C "${source_root}" config user.name 'Ops Sync Test'
git -C "${source_root}" config user.email 'ops-sync@example.invalid'
git -C "${source_root}" remote add origin "${remote}"

cat >"${source_root}/scripts/production/deploy.sh" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
"${MAE_STORM_EATS_BIN_DIR}/smoke"
printf 'VERSION=%s\n' "$1" >"${MAE_STORM_EATS_TEST_METADATA}"
MOCK
cat >"${source_root}/scripts/production/smoke.sh" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
printf 'release smoke\n' >>"${MAE_STORM_EATS_TEST_LOG}"
[[ "${MAE_STORM_EATS_TEST_FAIL_SMOKE:-0}" != 1 ]]
MOCK
cat >"${source_root}/scripts/production/backup.sh" <<'MOCK'
#!/usr/bin/env bash
exit 0
MOCK
git -C "${source_root}" add scripts
git -C "${source_root}" commit -qm 'test release scripts'
git -C "${source_root}" tag v0.2.7
git -C "${source_root}" push -q origin HEAD --tags
release_sha="$(git -C "${source_root}" rev-parse HEAD)"
infra_sha="$(printf 'f%.0s' {1..40})"
image="ghcr.io/drewnorman/mae-storm-eats@sha256:$(printf 'a%.0s' {1..64})"

cat >"${temporary}/bin/podman" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
case "$1 $2" in
    'pull ghcr.io/'*) printf 'image pulled\n' >>"${MAE_STORM_EATS_TEST_LOG}" ;;
    'image inspect') printf '%s\n' "${MAE_STORM_EATS_TEST_IMAGE_SHA}" ;;
    *) echo "Unexpected podman call: $*" >&2; exit 1 ;;
esac
MOCK
chmod +x "${temporary}/bin/podman"
printf 'legacy smoke\n' >"${temporary}/host/bin/smoke"
export PATH="${temporary}/bin:${PATH}"
export MAE_STORM_EATS_SOURCE_ROOT="${source_root}"
export MAE_STORM_EATS_OPS_ROOT="${temporary}/host/ops"
export MAE_STORM_EATS_BIN_PATH="${temporary}/host/bin"
export MAE_STORM_EATS_TEST_LOG="${temporary}/calls.log"
export MAE_STORM_EATS_TEST_METADATA="${temporary}/deployed.env"
export MAE_STORM_EATS_TEST_IMAGE_SHA="${release_sha}"
helper="${repo_root}/scripts/release/deploy-production-host.sh"

if bash "${helper}" 0.2.7 "${image}" "${infra_sha}" "${infra_sha}" >/dev/null 2>&1; then
    echo 'Host accepted a release tag revision mismatch.' >&2
    exit 1
fi
test -f "${MAE_STORM_EATS_BIN_PATH}/smoke"
test ! -L "${MAE_STORM_EATS_BIN_PATH}"

if MAE_STORM_EATS_TEST_IMAGE_SHA="${infra_sha}" \
    bash "${helper}" 0.2.7 "${image}" "${release_sha}" "${infra_sha}" >/dev/null 2>&1; then
    echo 'Host accepted an image revision mismatch.' >&2
    exit 1
fi
test ! -e "${MAE_STORM_EATS_TEST_METADATA}"
test ! -L "${MAE_STORM_EATS_BIN_PATH}"

if MAE_STORM_EATS_TEST_FAIL_SMOKE=1 \
    bash "${helper}" 0.2.7 "${image}" "${release_sha}" "${infra_sha}" >/dev/null 2>&1; then
    echo 'Host accepted a failing release smoke check.' >&2
    exit 1
fi
test "$(cat "${MAE_STORM_EATS_BIN_PATH}/smoke")" = 'legacy smoke'
test ! -e "${MAE_STORM_EATS_TEST_METADATA}"

bash "${helper}" 0.2.7 "${image}" "${release_sha}" "${infra_sha}" >/dev/null
test "$(readlink "${MAE_STORM_EATS_BIN_PATH}")" = "${MAE_STORM_EATS_OPS_ROOT}/${release_sha}"
grep -Fxq 'VERSION=0.2.7' "${MAE_STORM_EATS_TEST_METADATA}"
test -x "${MAE_STORM_EATS_BIN_PATH}/backup"
test -x "${MAE_STORM_EATS_BIN_PATH}/smoke"

bash "${helper}" 0.2.7 "${image}" "${release_sha}" "${infra_sha}" >/dev/null
test "$(readlink "${MAE_STORM_EATS_BIN_PATH}")" = "${MAE_STORM_EATS_OPS_ROOT}/${release_sha}"

if MAE_STORM_EATS_TEST_FAIL_SMOKE=1 \
    bash "${helper}" 0.2.7 "${image}" "${release_sha}" "${infra_sha}" >/dev/null 2>&1; then
    echo 'Repeat deployment accepted a failing release smoke check.' >&2
    exit 1
fi
test "$(readlink "${MAE_STORM_EATS_BIN_PATH}")" = "${MAE_STORM_EATS_OPS_ROOT}/${release_sha}"
echo 'Release operations staging, smoke failure, activation, and repeat checks passed.'
