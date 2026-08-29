# Production Operations Runbook

## First launch

1. Review `tofu plan`, then apply it to create the host, static IP, protected
   disk, DNS, secret, backup bucket, uptime check, and alerts.
2. Add the production `KEY=VALUE` bundle as a Secret Manager version. Never put
   it in OpenTofu variables, GitHub artifacts, shell tracing, or Git.
3. Install `deploy/quadlet`, `deploy/systemd`, production scripts, and the
   Caddyfile on the host. Enable `recipes-backup.timer` and the container units.
4. Configure the GitHub `production` environment with required reviewers and
   set its GCE instance/zone variables and workload-identity secrets.
5. Publish `v0.1.0`, dispatch **Deploy Production** with `0.1.0`, and approve the
   displayed immutable digest.
6. Confirm HTTPS, `/wp-login.php`, protected frontend/REST responses, active
   plugin/theme, Timber, and recorded release metadata.

Production begins empty. Schedule Angela's acceptance window with a content
freeze. She validates login, archive/search, a recipe, print output, and one
complete tagged-extension import including images and reviews.

## Angela's temporary Firefox extension

1. Clone `https://github.com/drewnorman/mae-storm-recipes` into a stable local
   directory.
2. Run `git fetch --tags` and `git checkout v0.1.0` (or the specifically approved
   tag). Never load a moving `master` checkout.
3. Open `about:debugging#/runtime/this-firefox`, choose **Load Temporary
   Add-on**, and select `extension/manifest.json`.
4. Connect only to `https://recipes.maestorm.com` using `pinterest-importer`
   and its WordPress Application Password from the password manager.
5. Firefox removes temporary add-ons at restart. Repeat step 3. Extension local
   storage normally survives a Firefox restart, but a temporary add-on's data
   can be removed when it is unloaded or Firefox data is cleared; keep the
   restricted credentials in the password manager for reconnection.
6. To update, fetch tags, check out the approved tag, then reload the temporary
   add-on from `about:debugging`.

The extension ID is stable and its source version matches the release. It is a
private, operator-managed development tool: no XPI, AMO listing, signing, or
automatic updates are provided. This installation model does not authorize
automated Pinterest extraction or eliminate Pinterest terms-of-service risk.

## Credentials and rotation

After initialization, create the handoff credential without recording it in
shell history or logs:

```sh
sudo podman exec -it recipes wp --allow-root user application-password create pinterest-importer "Angela Firefox" --porcelain
```

Put the result directly in Angela's password manager. Revoke it with `wp user
application-password delete` and issue a new one. Rotate database, admin,
frontend, and salt values by adding a new Secret Manager version, hydrating it,
and restarting the affected units. Database rotations require changing MariaDB
and WordPress credentials together.

## Deploy and rollback

The deployment workflow verifies the GitHub Release, resolves its immutable
GHCR digest, takes a pre-deploy backup, updates only the app container, runs
initialization/upgrades, and smoke-tests production. It records app/infra SHAs,
version, digest, and timestamp in `/var/lib/shared/releases/deployed.env`.

Failed smoke tests restore the prior image automatically. If an irreversible
database migration ran, stop writes and restore the pre-deploy GCS backup with
the restore procedure before restarting the prior image. Routine releases do
not rebuild or replace the VM.

## Disaster recovery and launch gate

Download a versioned GCS archive onto a disposable instance, restore it into an
empty shared disk, install the recorded image digest and units, then run the
initializer and smoke tests. Check recipe images and one private recipe. Record
the drill date, archive object generation, digest, and result outside the host.

Launch is complete only after a real production backup is present in GCS, one
disposable restore succeeds, an image rollback succeeds, alerts are verified,
and Angela completes acceptance with the matching release extension.
