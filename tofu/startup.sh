#!/bin/bash
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y git google-cloud-cli google-cloud-ops-agent mariadb-client podman

device=/dev/disk/by-id/google-recipes-shared
mountpoint=/var/lib/shared
mkdir -p "${mountpoint}"
if ! blkid "${device}" >/dev/null 2>&1; then
  mkfs.ext4 -F "${device}"
fi
mountpoint -q "${mountpoint}" || mount -o discard,defaults "${device}" "${mountpoint}"
mkdir -p "${mountpoint}"/{database,uploads,runtime,backup-staging,releases}
chmod 0700 "${mountpoint}/runtime" "${mountpoint}/backup-staging"

if [ ! -d /opt/recipes/source/.git ]; then
  git clone https://github.com/drewnorman/mae-storm-recipes.git /opt/recipes/source
else
  git -C /opt/recipes/source fetch --tags origin
fi
install -d /opt/recipes/bin /etc/containers/systemd /etc/systemd/system /etc/systemd/journald.conf.d
install -m 0755 /opt/recipes/source/scripts/production/*.sh /opt/recipes/bin/
for script in /opt/recipes/bin/*.sh; do mv "${script}" "${script%.sh}"; done
install -m 0644 /opt/recipes/source/deploy/quadlet/* /etc/containers/systemd/
install -m 0644 /opt/recipes/source/deploy/systemd/*.service /opt/recipes/source/deploy/systemd/*.timer /etc/systemd/system/
install -m 0644 /opt/recipes/source/deploy/systemd/journald-recipes.conf /etc/systemd/journald.conf.d/recipes.conf
install -m 0600 /opt/recipes/source/config/production/Caddyfile "${mountpoint}/runtime/Caddyfile"
systemctl daemon-reload
systemctl restart systemd-journald
systemctl enable --now recipes-backup.timer
