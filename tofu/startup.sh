#!/bin/bash
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y ca-certificates curl git gnupg mariadb-client podman

# The Cloud CLI and Ops Agent have separate Google package repositories.
if ! command -v gcloud >/dev/null 2>&1; then
  curl -fsSL https://packages.cloud.google.com/apt/doc/apt-key.gpg \
    | gpg --dearmor > /usr/share/keyrings/cloud.google.gpg
  echo 'deb [signed-by=/usr/share/keyrings/cloud.google.gpg] https://packages.cloud.google.com/apt cloud-sdk main' \
    > /etc/apt/sources.list.d/google-cloud-sdk.list
  apt-get update
  CLOUDSDK_SKIP_PY_COMPILATION=1 apt-get install -y google-cloud-cli
fi
if ! dpkg-query -W -f='${Status}' google-cloud-ops-agent 2>/dev/null | grep -Fq 'install ok installed'; then
  ops_installer="$(mktemp)"
  curl -fsSLo "${ops_installer}" https://dl.google.com/cloudagents/add-google-cloud-ops-agent-repo.sh
  bash "${ops_installer}" --also-install --version='2.*.*'
  rm -f -- "${ops_installer}"
fi

bootstrap_ref="$(curl -fsS -H 'Metadata-Flavor: Google' \
  http://metadata.google.internal/computeMetadata/v1/instance/attributes/mae-storm-eats-bootstrap-ref)"
if [[ ! "${bootstrap_ref}" =~ ^[0-9a-f]{40}$ ]]; then
  echo 'GCE mae-storm-eats-bootstrap-ref must be an approved 40-character commit SHA.' >&2
  exit 1
fi

device=/dev/disk/by-id/google-mae-storm-eats-shared
shared_root=/var/lib/shared
mkdir -p "${shared_root}"
if ! blkid "${device}" >/dev/null 2>&1; then
  mkfs.ext4 -F "${device}"
fi
disk_uuid="$(blkid -s UUID -o value "${device}")"
if ! grep -Fq "UUID=${disk_uuid} ${shared_root} ext4 " /etc/fstab; then
  printf 'UUID=%s %s ext4 defaults,nofail,discard 0 2\n' "${disk_uuid}" "${shared_root}" >> /etc/fstab
fi
mountpoint -q "${shared_root}" || mount "${shared_root}"
mkdir -p "${shared_root}"/{database,uploads,runtime,backup-staging,releases,caddy-data}
chmod 0700 "${shared_root}/runtime" "${shared_root}/backup-staging"

if [ ! -d /opt/mae-storm-eats/source/.git ]; then
  git clone --no-checkout https://github.com/drewnorman/mae-storm-eats.git /opt/mae-storm-eats/source
fi
git -C /opt/mae-storm-eats/source remote set-url origin https://github.com/drewnorman/mae-storm-eats.git
git -C /opt/mae-storm-eats/source fetch --tags origin
if ! git -C /opt/mae-storm-eats/source cat-file -e "${bootstrap_ref}^{commit}"; then
  echo "Approved mae-storm-eats-bootstrap-ref ${bootstrap_ref} is unavailable from the source repository." >&2
  exit 1
fi
git -C /opt/mae-storm-eats/source checkout --detach --force "${bootstrap_ref}"

install -d /opt/mae-storm-eats/bin /etc/containers/systemd /etc/systemd/system /etc/systemd/journald.conf.d
install -m 0755 /opt/mae-storm-eats/source/scripts/production/*.sh /opt/mae-storm-eats/bin/
for script in /opt/mae-storm-eats/bin/*.sh; do mv "${script}" "${script%.sh}"; done
for unit in /opt/mae-storm-eats/source/deploy/quadlet/*; do
  destination="/etc/containers/systemd/${unit##*/}"
  if [[ "${destination}" == /etc/containers/systemd/mae-storm-eats.container && -f "${destination}" ]]; then
    # deploy updates this Image to an approved digest; preserve it across boots.
    continue
  fi
  install -m 0644 "${unit}" "${destination}"
done
install -m 0644 /opt/mae-storm-eats/source/deploy/systemd/*.service /opt/mae-storm-eats/source/deploy/systemd/*.timer /etc/systemd/system/
install -m 0644 /opt/mae-storm-eats/source/deploy/systemd/journald-mae-storm-eats.conf /etc/systemd/journald.conf.d/mae-storm-eats.conf
install -m 0600 /opt/mae-storm-eats/source/config/production/Caddyfile "${shared_root}/runtime/Caddyfile"
systemctl daemon-reload
for unit in mae-storm-eats-db.service mae-storm-eats.service mae-storm-eats-proxy.service; do
  systemctl cat "${unit}" >/dev/null || {
    echo "Podman Quadlet did not generate ${unit}; check the installed Podman version and unit syntax." >&2
    exit 1
  }
done
systemctl restart systemd-journald
# deploy enables the app and backup timer only after the first release is healthy.
