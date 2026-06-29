# Recipes OpenTofu

This root is intentionally minimal until the app repository needs app-owned
cloud resources. State is stored in the dedicated recipes bucket:

```hcl
terraform {
  backend "gcs" {
    bucket = "drew-recipes-tofu-state"
    prefix = "prod"
  }
}
```

Initialize it with:

```sh
tofu init
```
