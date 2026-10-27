#!/usr/bin/env bash
set -euo pipefail

version="${1:?release version is required}"
image="${2:?immutable image digest is required}"
release_sha="${3:?release commit is required}"
infra_sha="${4:?deployment workflow commit is required}"
source_root="${MAE_STORM_EATS_SOURCE_ROOT:-/opt/mae-storm-eats/source}"
ops_root="${MAE_STORM_EATS_OPS_ROOT:-/opt/mae-storm-eats/ops}"
bin_path="${MAE_STORM_EATS_BIN_PATH:-/opt/mae-storm-eats/bin}"

[[ "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]
[[ "${image}" =~ ^ghcr\.io/drewnorman/mae-storm-eats@sha256:[0-9a-f]{64}$ ]]
[[ "${release_sha}" =~ ^[0-9a-f]{40}$ ]]
[[ "${infra_sha}" =~ ^[0-9a-f]{40}$ ]]

git -C "${source_root}" fetch --tags origin
tag_sha="$(git -C "${source_root}" rev-parse "refs/tags/v${version}^{commit}")"
if [[ "${tag_sha}" != "${release_sha}" ]]; then
    echo "Release tag does not match the approved commit." >&2
    exit 1
fi

podman pull "${image}"
image_sha="$(podman image inspect "${image}" --format '{{ index .Labels "org.opencontainers.image.revision" }}')"
if [[ "${image_sha}" != "${release_sha}" ]]; then
    echo "Approved image revision does not match the release tag." >&2
    exit 1
fi

install -d -m 0755 "${ops_root}"
stage="$(mktemp -d "${ops_root}/.stage.XXXXXXXX")"
trap 'rm -rf -- "${stage}"' EXIT
git -C "${source_root}" archive "${release_sha}" scripts/production | tar -x -C "${stage}"
install -d -m 0755 "${stage}/bin"
for script in "${stage}"/scripts/production/*.sh; do
    bash -n "${script}"
    name="${script##*/}"
    install -m 0755 "${script}" "${stage}/bin/${name%.sh}"
done

release_dir="${ops_root}/${release_sha}"
if [[ -e "${release_dir}" || -L "${release_dir}" ]]; then
    [[ -d "${release_dir}" && ! -L "${release_dir}" ]] \
        && diff -qr "${stage}/bin" "${release_dir}" >/dev/null || {
        echo "Existing release operations scripts differ from the approved tag." >&2
        exit 1
    }
else
    mv "${stage}/bin" "${release_dir}"
fi

# The deployed script uses backup and smoke from the same release directory.
# Leave the installed operations path untouched until deployment succeeds.
MAE_STORM_EATS_BIN_DIR="${release_dir}" "${release_dir}/deploy" \
    "${version}" "${image}" "${infra_sha}"

link="${ops_root}/.bin-link.$$"
trap 'rm -rf -- "${stage}"; rm -f -- "${link}"' EXIT
ln -s "${release_dir}" "${link}"
if [[ -d "${bin_path}" && ! -L "${bin_path}" ]]; then
    legacy="$(mktemp -d "${ops_root}/legacy.XXXXXXXX")"
    rmdir "${legacy}"
    mv "${bin_path}" "${legacy}"
elif [[ -e "${bin_path}" && ! -L "${bin_path}" ]]; then
    echo "Installed operations path is not a directory or symlink." >&2
    exit 1
fi
mv -Tf "${link}" "${bin_path}"
echo "Activated operations scripts from ${release_sha}."
