set dotenv-load := true
set shell := ["bash", "-euo", "pipefail", "-c"]

export XDG_CACHE_HOME := env_var_or_default("MAE_STORM_EATS_CACHE_HOME", "/tmp/mae-storm-eats-cache")
export COREPACK_HOME := env_var_or_default("MAE_STORM_EATS_COREPACK_HOME", "/tmp/mae-storm-eats-corepack")

default:
  just --list

bootstrap:
  @scripts/dev/bootstrap.sh

fmt:
  @just check-dependencies
  @corepack yarn fmt
  @composer run-script fmt

check:
  @just check-dependencies
  just check-editorconfig
  @scripts/test/browser-import.sh
  @scripts/test/php-syntax.sh
  @scripts/test/timber-boundary.sh
  @scripts/test/image-pins.sh
  @scripts/test/production-contract.sh
  @corepack yarn fmt:check
  @corepack yarn typecheck
  @corepack yarn build
  @composer validate --strict --no-check-lock

check-dependencies:
  @test -d node_modules || { echo "node_modules is missing; run 'just bootstrap'." >&2; exit 1; }
  @test -f vendor/autoload.php || { echo "vendor/autoload.php is missing; run 'just bootstrap'." >&2; exit 1; }

check-editorconfig:
  @scripts/dev/check-editorconfig.sh

install-hooks:
  @git config core.hooksPath .githooks
  @echo "Installed git hooks from .githooks"

commitlint from="HEAD~1" to="HEAD":
  @just check-dependencies
  @corepack yarn commitlint --from "{{from}}" --to "{{to}}"

build-artifact version=`git rev-parse --short HEAD`:
  @scripts/release/build-artifact.sh {{version}}

test-artifact version=`git rev-parse --short HEAD`:
  @scripts/test/release-artifact.sh {{version}}

dev-up:
  @scripts/dev/up.sh

dev-down:
  @podman-compose -f compose.yaml down

dev-logs:
  @podman-compose -f compose.yaml logs -f

dev-reset:
  @podman-compose -f compose.yaml down -v

test-dev-boot:
  @scripts/test/podman-boot-smoke.sh

check-infra:
  @tofu -chdir=tofu fmt -check
  @tofu -chdir=tofu init -backend=false
  @tofu -chdir=tofu validate
