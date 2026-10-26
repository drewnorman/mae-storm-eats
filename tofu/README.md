# Recipes OpenTofu

This root provisions the one production GCE host, static IP, separately
protected persistent disk, Cloudflare DNS, Secret Manager container, versioned
backup bucket, service account, firewall, HTTPS uptime check, and alerts. There
is no staging environment. State is stored in the shared personal OpenTofu
state bucket:

```hcl
terraform {
  backend "gcs" {
    bucket = "drew-infra-tofu-state"
    prefix = "recipes/prod"
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
