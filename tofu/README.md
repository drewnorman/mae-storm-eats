# Mae Storm Eats OpenTofu

This root provisions the one production GCE host, static IP, separately
protected persistent disk, Cloudflare DNS, Secret Manager container, versioned
backup bucket, runtime and deploy service accounts, firewall, HTTPS uptime check, and alerts. There
is no staging environment. State is stored in the shared personal OpenTofu
state bucket:

```hcl
terraform {
  backend "gcs" {
    bucket = "drew-infra-tofu-state"
    prefix = "mae-storm-eats/prod"
  }
}
```

Initialize it with:

```sh
tofu init
tofu validate
tofu plan -var-file=production.tfvars
```

Set `bootstrap_ref` in the untracked `production.tfvars` to the approved
release tag's full 40-character commit SHA. The host startup script checks out
that exact commit before installing operations files, so the VM must be able to
clone the source repository. The application containers remain dormant until
the approved deployment workflow runs. Verify a fresh host bootstrap before
the first deploy.

Do not place the production secret payload in `.tfvars`: add it as a Secret
Manager version after apply so its values never enter OpenTofu state. Review the
plan before apply; the VM and data disk have `prevent_destroy` guards.

The shared GCP project already owns the `github-actions` workload identity
pool. This root adds a dedicated `mae-storm-eats` provider that accepts only
`drewnorman/mae-storm-eats` production-environment jobs dispatched from
`master`, then grants that identity access to a dedicated deploy service
account. Its OS Admin Login and IAP tunnel roles are scoped to this VM; the
workflow connects with `gcloud compute ssh --tunnel-through-iap`. An IAP-only
TCP 22 firewall allow plus an explicit direct-SSH deny prevent the default VPC's
broad SSH rule from opening this VM. OS Login ignores instance
`ssh-keys` metadata, so the VM has no SSH key variable.

After apply, use `workload_identity_provider` and
`deploy_service_account_email` outputs for the GitHub `production` environment
secrets, and set `GCP_PROJECT_ID`, `GCE_ZONE`, and `GCE_INSTANCE` environment
variables. Keep required reviewers enabled before dispatching a deployment.
