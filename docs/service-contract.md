# Recipes Production Service Contract

## Release

`drewnorman/mae-storm-recipes` releases one SemVer for the backend, plugin,
theme, package, and temporary Firefox extension. Tags use `v<version>`. A
GitHub Release permanently contains `recipes-<version>.tar.gz`, checksums,
compatibility notes, and the immutable digest for
`ghcr.io/drewnorman/mae-storm-recipes:<version>`.

Release creation never deploys production. The manually dispatched deployment
workflow resolves the released tag to a digest and pauses at the protected
GitHub `production` environment for approval.

## Runtime

- Public URL: `https://recipes.maestorm.com`
- Health endpoint: `/wp-login.php`
- Required plugin/theme: `mae-storm-recipes`
- Persistent root: `/var/lib/shared`
- Persistent paths: `database`, `uploads`, `runtime`, `backup-staging`,
  `caddy-data`, and `releases`
- Deployed code is read-only in the OCI image. WordPress file editing,
  application updates, and core updates are disabled.

The Secret Manager secret `recipes-production` is a newline-delimited,
shell-safe `KEY=VALUE` bundle. Values must not contain unescaped shell syntax.
It must define:

- `WORDPRESS_DB_HOST`, `WORDPRESS_DB_NAME`, `WORDPRESS_DB_USER`, and
  `WORDPRESS_DB_PASSWORD`
- matching `MARIADB_DATABASE`, `MARIADB_USER`, `MARIADB_PASSWORD`, and a unique
  `MARIADB_ROOT_PASSWORD`
- `RECIPES_SITE_URL`, `RECIPES_SITE_TITLE`, `RECIPES_ADMIN_USER`,
  `RECIPES_ADMIN_PASSWORD`, `RECIPES_ADMIN_EMAIL`, and
  `RECIPES_IMPORTER_EMAIL`
- `MAE_STORM_RECIPES_SHARED_PASSWORD`
- `RECIPES_BACKUP_BUCKET` (the bucket name emitted by OpenTofu)
- WordPress `AUTH_KEY`, `SECURE_AUTH_KEY`, `LOGGED_IN_KEY`, `NONCE_KEY`, and the
  four corresponding `_SALT` values

Hydration writes `/var/lib/shared/runtime/wordpress.env` as root with mode
`0600`; scripts validate names without printing values.

## Backup and Restore

The daily timer dumps MariaDB first and refuses to upload a backup if the dump
fails or is empty. Its versioned GCS archive includes the database, uploads,
runtime configuration, app version, app/infra SHAs, and image digest. Bucket
versioning is enabled with a 90-day retention rule.

Local disposable containers exercise backup and restore with synthetic data;
there is no hosted staging environment. A real production restore requires the
database container running and the root-only runtime secret bundle sourced on
the host. Run `RECIPES_CONFIRM_RESTORE=YES /opt/recipes/bin/restore <archive>
<shared-root>` only during disaster recovery: it replaces the database,
uploads, config, and release metadata. Start the app and run smoke checks after
restoring the recorded image digest.
