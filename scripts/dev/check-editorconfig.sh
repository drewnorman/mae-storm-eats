#!/usr/bin/env bash
set -euo pipefail

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "Not inside a git repository."
  exit 1
fi

status=0

while IFS= read -r -d '' file; do
  if [[ ! -e "$file" ]]; then
    continue
  fi
  case "$file" in
    .git/*|node_modules/*|vendor/*|.yarn/*|config/dev/uploads/*)
      continue
      ;;
  esac

  if [[ -s "$file" ]]; then
    if [[ "$(tail -c 1 "$file")" != "" ]]; then
      echo "Missing final newline: $file"
      status=1
    fi
  fi

  if [[ "$file" != *.md ]]; then
    if grep -Iq . "$file" && grep -n '[[:blank:]]$' "$file" >/dev/null; then
      echo "Trailing whitespace: $file"
      status=1
    fi
  fi
done < <(git ls-files -z --cached --others --exclude-standard)

exit "$status"
