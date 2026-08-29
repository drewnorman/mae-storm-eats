# Recipes

Private WordPress recipe application for `recipes.maestorm.com`.

This repository owns the app code and local development tooling:

- First-party WordPress recipe plugin.
- Timber-based WordPress theme.
- Recipe import and review workflows.
- Restricted Firefox WebExtension for Pinterest board imports.
- Local Podman Compose environment.
- App CI, release automation, and formatting checks.

This repository also owns the single production stack's OpenTofu, Quadlet,
backup, restore, release, and approved deployment automation.

## Development

Enter the Nix dev shell and bootstrap the locked project dependencies and
compiled theme assets:

```sh
nix develop
just bootstrap
just dev-up
```

Common commands:

```sh
just bootstrap
just check
just fmt
just install-hooks
just dev-up
just dev-logs
just dev-down
```

`just bootstrap` runs immutable Yarn and Composer installs, builds the theme
assets, creates the local uploads directory, and configures the repository Git
hooks. Run it after dependency-lock changes or when preparing a clean checkout.

Commit messages must follow Conventional Commits. `just bootstrap` and
`just install-hooks` configure the local `commit-msg` hook, and CI validates
commit messages on pull requests and pushes to `master`.

The local WordPress environment is defined in `compose.yaml`. Plugin and theme
code is mounted from `plugins/mae-storm-recipes` and
`themes/mae-storm-recipes`.

`just dev-up` verifies its bootstrap outputs and Podman prerequisites, installs
WordPress when the database is new, and activates the Mae Storm Recipes plugin
and theme. It then runs the containers in the foreground with live logs. The app
is available at <http://wordpress.recipes.localhost>. Press Ctrl-C to stop the
containers.

The Compose stack expects the external `local-proxy` Podman network and its
shared Traefik proxy to already be running. This integration is an intentional
part of the local environment.

A new local database uses WordPress administrator `admin` with password
`password`. The private frontend uses `password` as well. These development-only
defaults and the local site metadata can be overridden in an ignored `.env`;
copy `.env.example` as a starting point. Overrides only affect a new WordPress
installation and do not replace credentials in an existing database.

Compiled theme assets are generated and ignored. Run `corepack yarn dev`
separately when working on theme CSS or JavaScript. `just dev-reset` deletes the
local database and WordPress volumes; the next `just dev-up` recreates them with
the configured development defaults.

WordPress and MariaDB images use explicit patch tags and multi-architecture
digests. When updating an image, resolve the new tag's manifest-list digest,
change the tag and digest together in `compose.yaml`, then run `just check`.

Pinterest imports are performed from Firefox inside the owner's existing
Pinterest session. See [docs/browser-import.md](docs/browser-import.md).

## Plugin And Theme Contract

The plugin and theme ship together in the app artifact but keep separate
WordPress responsibilities. The plugin owns durable app behavior: recipe content
types, taxonomies, meta, imports, admin review, CLI commands, REST protection,
and the shared-password access gate. The theme owns Timber templates, assets,
layout, and frontend query presentation.

Timber is a required app dependency. The active app theme registers the Timber
locations used by plugin-facing templates, including the private login template
rendered by the access gate.

## Production Release and Operations

Build the app artifact consumed by the shared infra repo:

```sh
just build-artifact
```

The build stages the committed source, compiles theme assets, installs
production Composer dependencies, and produces a normalized archive. Use
`just test-artifact` to build it twice and verify its contents and byte-for-byte
reproducibility.

GitHub Releases publish this bundle, its checksums, compatibility notes, and a
pinned GHCR image. Deployments are separate manual workflows protected by the
GitHub `production` environment. The service interface is in
[docs/service-contract.md](docs/service-contract.md); deployment, extension
handoff, backup, rollback, and disaster recovery are in
[docs/operations.md](docs/operations.md).

App-owned OpenTofu state, when this repository needs it, uses the shared personal state bucket from `infra-state`: `gs://drew-infra-tofu-state` with prefix `recipes/prod`. The backend configuration lives in `tofu/versions.tf`.
