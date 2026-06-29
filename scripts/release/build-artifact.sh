#!/usr/bin/env bash
set -eu

version="${1:-$(git rev-parse --short HEAD)}"
artifact_dir="dist"
artifact="${artifact_dir}/recipes-${version}.tar.gz"

mkdir -p "${artifact_dir}"

git archive --format=tar --prefix="recipes/" HEAD \
  | gzip -n > "${artifact}"

echo "${artifact}"
