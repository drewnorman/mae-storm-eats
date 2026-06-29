# Recipes OpenTofu

This root is intentionally minimal until the app repository needs app-owned
cloud resources. State is stored in the shared personal OpenTofu state bucket
under the recipes production prefix published by `infra-state`:

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
```
