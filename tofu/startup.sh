#!/bin/bash
set -euo pipefail

select_ops_ref() {
  local initial_ref="$1" metadata="$2"
  [[ "${initial_ref}" =~ ^[0-9a-f]{40}$ ]] || {
    echo 'Bootstrap ref must be a 40-character commit SHA.' >&2
    return 1
  }
  if [[ ! -f "${metadata}" ]]; then
    printf '%s\n' "${initial_ref}"
    return
  fi

  local revisions=()
  mapfile -t revisions < <(sed -n 's/^APP_SHA=//p' "${metadata}")
  if [[ "${#revisions[@]}" -ne 1 || ! "${revisions[0]}" =~ ^[0-9a-f]{40}$ ]]; then
    echo 'Deployed release metadata has no valid, unique APP_SHA.' >&2
    return 1
  fi
  printf '%s\n' "${revisions[0]}"
}

# Allow boot revision selection to be tested without running host setup.
if [[ "${1:-}" == --select-ops-ref ]]; then
  select_ops_ref "${2:?bootstrap ref is required}" "${3:?metadata path is required}"
  exit
fi

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
ops_ref="$(select_ops_ref "${bootstrap_ref}" "${shared_root}/releases/deployed.env")"

if [ ! -d /opt/mae-storm-eats/source/.git ]; then
  git clone --no-checkout https://github.com/drewnorman/mae-storm-eats.git /opt/mae-storm-eats/source
fi
git -C /opt/mae-storm-eats/source remote set-url origin https://github.com/drewnorman/mae-storm-eats.git
git -C /opt/mae-storm-eats/source fetch --tags origin
if ! git -C /opt/mae-storm-eats/source cat-file -e "${bootstrap_ref}^{commit}"; then
  echo "Approved bootstrap revision ${bootstrap_ref} is unavailable from the source repository." >&2
  exit 1
fi
if ! git -C /opt/mae-storm-eats/source cat-file -e "${ops_ref}^{commit}"; then
  echo "Approved operations revision ${ops_ref} is unavailable from the source repository." >&2
  exit 1
fi
# Keep host units and proxy configuration at their separately approved
# bootstrap revision; only operations scripts follow the deployed app release.
git -C /opt/mae-storm-eats/source checkout --detach --force "${bootstrap_ref}"

install -d /opt/mae-storm-eats/ops /etc/containers/systemd /etc/systemd/system /etc/systemd/journald.conf.d
stage="$(mktemp -d /opt/mae-storm-eats/ops/.stage.XXXXXXXX)"
git -C /opt/mae-storm-eats/source archive "${ops_ref}" scripts/production | tar -x -C "${stage}"
install -d "${stage}/bin"
for script in "${stage}"/scripts/production/*.sh; do
  bash -n "${script}"
  name="${script##*/}"
  install -m 0755 "${script}" "${stage}/bin/${name%.sh}"
done
release_dir="/opt/mae-storm-eats/ops/${ops_ref}"
if [[ -e "${release_dir}" || -L "${release_dir}" ]]; then
  [[ -d "${release_dir}" && ! -L "${release_dir}" ]] || {
    echo "Operations release path is not a directory: ${release_dir}" >&2
    exit 1
  }
  if ! diff -qr "${stage}/bin" "${release_dir}" >/dev/null; then
    previous="$(mktemp -d /opt/mae-storm-eats/ops/.previous.XXXXXXXX)"
    rmdir "${previous}"
    mv "${release_dir}" "${previous}"
    if ! mv "${stage}/bin" "${release_dir}"; then
      mv "${previous}" "${release_dir}"
      exit 1
    fi
    rm -rf -- "${previous}"
  fi
else
  mv "${stage}/bin" "${release_dir}"
fi
rm -rf -- "${stage}"

link="/opt/mae-storm-eats/ops/.bin-link.$$"
ln -s "${release_dir}" "${link}"
if [[ -d /opt/mae-storm-eats/bin && ! -L /opt/mae-storm-eats/bin ]]; then
  legacy="$(mktemp -d /opt/mae-storm-eats/ops/legacy.XXXXXXXX)"
  rmdir "${legacy}"
  mv /opt/mae-storm-eats/bin "${legacy}"
elif [[ -e /opt/mae-storm-eats/bin && ! -L /opt/mae-storm-eats/bin ]]; then
  echo 'Installed operations path is not a directory or symlink.' >&2
  exit 1
fi
mv -Tf "${link}" /opt/mae-storm-eats/bin
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
