#!/usr/bin/env bash
set -eu

echo "importer fixtures smoke: future check will import live, archived, DOCX, image, malformed, and duplicate fixtures into recipe candidates."

for directory in live-pages archived-pages docx images malformed duplicates; do
  if [ ! -d "fixtures/${directory}" ]; then
    echo "importer fixtures smoke: missing fixtures/${directory}" >&2
    exit 1
  fi
done

echo "importer fixtures smoke: expected fixture directories exist."
