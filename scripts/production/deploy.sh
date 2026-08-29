#!/usr/bin/env bash
set -euo pipefail

version="${1:?release version is required}"
image="${2:?immutable image digest is required}"
infra_sha="${3:?infra SHA is required}"
shared_root="${RECIPES_SHARED_ROOT:-/var/lib/shared}"
quadlet="/etc/containers/systemd/recipes.container"
metadata="${shared_root}/releases/deployed.env"
previous="${shared_root}/releases/previous.env"
backup_marker="${shared_root}/releases/pre-deploy-backup"

[[ "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]
[[ "${image}" =~ ^ghcr\.io/drewnorman/mae-storm-recipes@sha256:[0-9a-f]{64}$ ]]

set -a
source "${shared_root}/runtime/wordpress.env"
set +a

mkdir -p "${shared_root}/releases"
if [[ -f "${metadata}" ]]; then cp "${metadata}" "${previous}"; fi

# A failed or empty dump aborts before any container is changed.
/opt/recipes/bin/backup
date -u +%Y-%m-%dT%H:%M:%SZ >"${backup_marker}"

podman pull "${image}"
old_image="$(sed -n 's/^IMAGE=//p' "${previous}" 2>/dev/null || true)"
sed -i "s|^Image=.*|Image=${image}|" "${quadlet}"
systemctl daemon-reload
systemctl restart recipes.service
systemctl restart recipes-proxy.service

rollback() {
    if [[ -n "${old_image}" ]]; then
        sed -i "s|^Image=.*|Image=${old_image}|" "${quadlet}"
        systemctl daemon-reload
        systemctl restart recipes.service
    fi
    echo "Deployment smoke test failed; previous image restored. Database restore may be required if an irreversible migration ran." >&2
}
trap rollback ERR

podman exec recipes /usr/local/bin/recipes-initialize
/opt/recipes/bin/smoke

app_sha="$(podman image inspect "${image}" --format '{{ index .Labels "org.opencontainers.image.revision" }}' 2>/dev/null || true)"
umask 077
printf 'VERSION=%s\nIMAGE=%s\nAPP_SHA=%s\nINFRA_SHA=%s\nDEPLOYED_AT=%s\n' \
    "${version}" "${image}" "${app_sha}" "${infra_sha}" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >"${metadata}"
trap - ERR
echo "Deployed ${version} at ${image}."
