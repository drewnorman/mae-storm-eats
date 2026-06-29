terraform {
  required_version = ">= 1.8.0"

  backend "gcs" {
    bucket = "drew-infra-tofu-state"
    prefix = "recipes/prod"
  }
}
