#!/usr/bin/env bash
set -euo pipefail

version="${1:?release version is required}"
image="${2:?immutable image digest is required}"
infra_sha="${3:?infra SHA is required}"
shared_root="${MAE_STORM_EATS_SHARED_ROOT:-/var/lib/shared}"
quadlet="${MAE_STORM_EATS_QUADLET_PATH:-/etc/containers/systemd/mae-storm-eats.container}"
bin_dir="${MAE_STORM_EATS_BIN_DIR:-/opt/mae-storm-eats/bin}"
metadata="${shared_root}/releases/deployed.env"
previous="${shared_root}/releases/previous.env"
launch_marker="${shared_root}/releases/launch-enabled"

[[ "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]
[[ "${image}" =~ ^ghcr\.io/drewnorman/mae-storm-eats@sha256:[0-9a-f]{64}$ ]]

mkdir -p "${shared_root}/releases"
first_deploy=0
if [[ ! -f "${metadata}" ]]; then first_deploy=1; fi
cleanup_first_deploy() {
    status=$?
    if [[ "${status}" -ne 0 && "${first_deploy}" -eq 1 ]]; then
        rm -f -- "${launch_marker}"
        systemctl stop mae-storm-eats-proxy.service mae-storm-eats.service mae-storm-eats-db.service >/dev/null 2>&1 || true
    fi
}
trap cleanup_first_deploy EXIT

systemctl start mae-storm-eats-secrets.service
set -a
source "${shared_root}/runtime/wordpress.env"
set +a
# Quadlet services remain dormant until an approved deploy begins.
touch "${launch_marker}"
systemctl start mae-storm-eats-db.service

# systemd considers the database unit started before MariaDB finishes booting.
for attempt in {1..45}; do
    if podman exec mae-storm-eats-db mariadb-admin ping --silent >/dev/null 2>&1; then
        break
    fi
    if [[ "${attempt}" -eq 45 ]]; then
        echo "MariaDB did not become ready within 90 seconds." >&2
        exit 1
    fi
    sleep 2
done

if [[ "${first_deploy}" -eq 0 ]]; then
    cp "${metadata}" "${previous}"
    # An upgrade must have a usable backup before changing the app image.
    "${bin_dir}/backup"
fi

podman pull "${image}"
old_image="$(sed -n 's/^Image=//p' "${quadlet}")"
[[ -n "${old_image}" ]] || { echo "App Quadlet is missing its Image setting." >&2; exit 1; }
quadlet_changed=0

rollback() {
    status=$?
    trap - ERR
    if [[ "${quadlet_changed}" -eq 1 ]]; then
        sed -i "s|^Image=.*|Image=${old_image}|" "${quadlet}"
        systemctl daemon-reload
        if [[ "${first_deploy}" -eq 1 ]]; then
            systemctl stop mae-storm-eats-proxy.service mae-storm-eats.service || true
        else
            systemctl restart mae-storm-eats.service || true
            systemctl restart mae-storm-eats-proxy.service || true
        fi
    fi
    echo "Deployment failed; app image configuration restored. Database restore may be required if an irreversible migration ran." >&2
    exit "${status}"
}
trap rollback ERR

quadlet_changed=1
sed -i "s|^Image=.*|Image=${image}|" "${quadlet}"
systemctl daemon-reload
systemctl restart mae-storm-eats.service
# The official WordPress entrypoint copies core into the container's empty
# /var/www/html and writes wp-config.php before starting Apache. systemd
# reports the container started before that initialization finishes.
wait_for_wordpress() {
    local attempt
    for attempt in {1..60}; do
        if podman exec mae-storm-eats test -f /var/www/html/wp-load.php >/dev/null 2>&1 \
            && podman exec mae-storm-eats test -f /var/www/html/wp-config.php >/dev/null 2>&1 \
            && podman exec mae-storm-eats curl --fail --silent --show-error --max-time 5 \
                --output /dev/null http://127.0.0.1/wp-login.php >/dev/null 2>&1; then
            return 0
        fi
        if [[ "${attempt}" -eq 60 ]]; then
            echo "WordPress container did not finish initialization after 60 readiness checks." >&2
            return 1
        fi
        sleep 2
    done
}
wait_for_wordpress
systemctl restart mae-storm-eats-proxy.service
podman exec mae-storm-eats /usr/local/bin/mae-storm-eats-initialize
"${bin_dir}/smoke"

app_sha="$(podman image inspect "${image}" --format '{{ index .Labels "org.opencontainers.image.revision" }}')"
[[ "${app_sha}" =~ ^[0-9a-f]{40}$ ]] || {
    echo "Approved image is missing its 40-character source revision label." >&2
    exit 1
}
umask 077
printf 'VERSION=%s\nIMAGE=%s\nAPP_SHA=%s\nINFRA_SHA=%s\nDEPLOYED_AT=%s\n' \
    "${version}" "${image}" "${app_sha}" "${infra_sha}" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >"${metadata}"
trap - ERR
if [[ "${first_deploy}" -eq 1 ]]; then
    # The first backup must contain a real database and deployed.env metadata.
    "${bin_dir}/backup"
fi
systemctl enable --now mae-storm-eats-backup.timer
echo "Deployed ${version} at ${image}."
