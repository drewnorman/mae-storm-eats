# Recipes OpenTofu

This root provisions the one production GCE host, static IP, separately
protected persistent disk, Cloudflare DNS, Secret Manager container, versioned
backup bucket, service account, firewall, HTTPS uptime check, and alerts. State
is stored in the shared personal OpenTofu state bucket:

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

Do not place the production secret payload in `.tfvars`: add it as a Secret
Manager version after apply so its values never enter OpenTofu state. Review the
plan before apply; the VM and data disk have `prevent_destroy` guards.
