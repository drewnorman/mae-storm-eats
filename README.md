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
just dev-down
```

The local WordPress environment is defined in `compose.yaml`. Future plugin and theme code will be mounted from `plugins/mae-storm-recipes` and `themes/mae-storm-recipes`.
