# Production Operations Runbook

## First launch

There is no staging environment. Run the repository checks and local container
drills, then launch directly to production during the owner's acceptance window.

1. Publish the approved release and verify its bundle, checksums, and GHCR image
   digest. Record the release tag's full commit SHA as `bootstrap_ref` in the
   untracked `production.tfvars`. The production host must be able to clone the
   source repository at that commit.
2. Review `tofu plan -var-file=production.tfvars`, then apply it to create the
   host, static IP, protected disk, DNS, secret, backup bucket, uptime check,
   and alerts. The startup script installs host operations files at exactly
   `bootstrap_ref`; the Quadlet containers remain dormant until deployment.
3. Add the production `KEY=VALUE` bundle as a Secret Manager version. Never put
   it in OpenTofu variables, GitHub artifacts, shell tracing, or Git.
4. Configure the GitHub `production` environment with required reviewers and
   set its GCE instance/zone variables and workload-identity secrets.
5. Dispatch **Deploy Production** with the approved version and approve the
   displayed immutable digest. The first deployment initializes the empty
   database, checks the site, records release metadata, and takes its first
   backup. The backup timer starts only after this succeeds.
6. Confirm HTTPS, `/wp-login.php`, protected frontend/REST responses, active
   plugin/theme, Timber, release metadata, the first backup in GCS, and the
   uptime and backup alerts.

Production begins empty. Schedule the owner's acceptance window with a content
freeze. The owner validates login, archive/search, a recipe, print output, and one
complete tagged-extension import including images and reviews.

## Owner's temporary Firefox extension

1. Clone `https://github.com/drewnorman/mae-storm-eats` into a stable local
   directory.
2. Run `git fetch --tags` and `git checkout v0.1.0` (or the specifically approved
   tag). Never load a moving `master` checkout.
3. Open `about:debugging#/runtime/this-firefox`, choose **Load Temporary
   Add-on**, and select `extension/manifest.json`.
4. Connect only to `https://eats.maestorm.com` using `pinterest-importer`
   and its WordPress Application Password from the password manager.
5. Firefox removes temporary add-ons at restart. Repeat step 3. Extension local
   storage normally survives a Firefox restart, but a temporary add-on's data
   can be removed when it is unloaded or Firefox data is cleared; keep the
   restricted credentials in the password manager for reconnection.
6. To update, fetch tags, check out the approved tag, then reload the temporary
   add-on from `about:debugging`.

The extension ID is stable and its source version matches the release. It is an
operator-managed development tool: no XPI, AMO listing, signing, or
automatic updates are provided. This installation model does not authorize
automated Pinterest extraction or eliminate Pinterest terms-of-service risk.

## Credentials and rotation

After initialization, create the handoff credential without recording it in
shell history or logs:

```sh
sudo podman exec -it mae-storm-eats wp --allow-root user application-password create pinterest-importer "Owner Firefox" --porcelain
```

Put the result directly in the owner's password manager. Revoke it with `wp user
application-password delete` and issue a new one. Rotate database, admin,
frontend, and salt values by adding a new Secret Manager version, hydrating it,
and restarting the affected units. Database rotations require changing MariaDB
and WordPress credentials together.

## Deploy and rollback

The deployment workflow verifies the GitHub Release and resolves its immutable
GHCR digest. Upgrades take a pre-deploy backup before changing the app image;
the initial installation takes its first backup after initialization and smoke
checks. It records app/infra SHAs, version, digest, and timestamp in
`/var/lib/shared/releases/deployed.env`.

Failed upgrade smoke tests restore the prior image automatically. A failed first
deployment has no prior image to restore and leaves its Quadlet units dormant
on reboot. If an irreversible database migration ran, stop writes and restore
the pre-deploy GCS backup with the restore procedure before restarting the prior
image. Routine releases do not rebuild or replace the VM.

## Disaster recovery and launch gate

The local backup/restore drill uses disposable containers and synthetic data.
For a real disaster recovery, download a versioned GCS archive, stop writes,
start the database container, and source the root-only runtime bundle before
running restore:

```sh
set -a
source /var/lib/shared/runtime/wordpress.env
set +a
MAE_STORM_EATS_CONFIRM_RESTORE=YES /opt/mae-storm-eats/bin/restore /path/to/archive.tar.gz /var/lib/shared
```

The restore replaces the database, uploads, runtime configuration, and release
metadata; do not run it against a healthy production site as a launch test.
After a real recovery, restore the recorded image digest and units, then run
the initializer and smoke tests. Check recipe images and one private recipe.

Launch is complete only after a real production backup is present in GCS, the
local restore and image rollback drills pass, alerts are verified, and the owner
completes acceptance with the matching release extension.
