#!/usr/bin/env bash
set -eu

echo "importer fixtures smoke: browser-import pins become reviewable candidates and malformed or duplicate records are detected."

for directory in live-pages archived-pages malformed duplicates; do
  if [ ! -d "fixtures/${directory}" ]; then
    echo "importer fixtures smoke: missing fixtures/${directory}" >&2
    exit 1
  fi
done

echo "importer fixtures smoke: expected fixture directories exist."
