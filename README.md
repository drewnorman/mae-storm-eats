# Recipes

Private WordPress recipe application for `recipes.maestorm.com`.

This repository owns the app code and local development tooling:

- First-party WordPress recipe plugin.
- Timber-based WordPress theme.
- Recipe import and review workflows.
- Local Podman Compose environment.
- App CI, release automation, and formatting checks.

Shared host, DNS, GCP, backup, and production orchestration concerns belong in the separate infrastructure repository.

## Development

Enter the Nix dev shell, then install project tools when network access is available:

```sh
nix develop
corepack enable
yarn install
composer install
```

Common commands:

```sh
just check
just fmt
just install-hooks
just dev-up
just dev-logs
just dev-down
```

The local WordPress environment is defined in `compose.yaml`. Plugin and theme
code is mounted from `plugins/mae-storm-recipes` and
`themes/mae-storm-recipes`.

`just dev-up` verifies the plugin mount, activates the Mae Storm Recipes plugin
when necessary, and then runs the containers in the foreground with live logs.
The app is available at <http://wordpress.recipes.localhost>. Press Ctrl-C to
stop the containers.

The Compose stack expects the external `local-proxy` Podman network and its
shared Traefik proxy to already be running. Theme assets are committed for the
initial boot; run `corepack yarn dev` separately when working on theme CSS or
JavaScript.

## Plugin And Theme Contract

The plugin and theme ship together in the app artifact but keep separate
WordPress responsibilities. The plugin owns durable app behavior: recipe content
types, taxonomies, meta, imports, admin review, CLI commands, REST protection,
and the shared-password access gate. The theme owns Timber templates, assets,
layout, and frontend query presentation.

Timber is a required app dependency. The active app theme registers the Timber
locations used by plugin-facing templates, including the private login template
rendered by the access gate.
