set dotenv-load := true

export XDG_CACHE_HOME := env_var_or_default("XDG_CACHE_HOME", "/tmp/corepack-cache")

default:
  just --list

fmt:
  @if command -v corepack >/dev/null 2>&1 && [ -d node_modules ]; then XDG_CACHE_HOME=/tmp/corepack-cache corepack yarn fmt; else echo "Skipping JS format: run 'corepack yarn install' first."; fi
  @if command -v composer >/dev/null 2>&1; then composer run-script fmt; else echo "Skipping PHP format: composer is not installed."; fi

check:
  just check-editorconfig
  @if command -v corepack >/dev/null 2>&1 && [ -d node_modules ]; then XDG_CACHE_HOME=/tmp/corepack-cache corepack yarn fmt:check; else echo "Skipping JS checks: run 'corepack yarn install' first."; fi
  @if command -v composer >/dev/null 2>&1; then composer validate --strict --no-check-lock; else echo "Skipping Composer checks: composer is not installed."; fi

check-editorconfig:
  @scripts/dev/check-editorconfig.sh

install-hooks:
  @git config core.hooksPath .githooks
  @echo "Installed git hooks from .githooks"

commitlint range="HEAD~1..HEAD":
  @if command -v corepack >/dev/null 2>&1 && [ -d node_modules ]; then XDG_CACHE_HOME=/tmp/corepack-cache corepack yarn commitlint --from {{range}}; else echo "Skipping commitlint: run 'corepack yarn install' first."; fi

dev-up:
  @scripts/dev/up.sh

dev-down:
  @podman-compose -f compose.yaml down

dev-logs:
  @podman-compose -f compose.yaml logs -f

dev-reset:
  @podman-compose -f compose.yaml down -v
