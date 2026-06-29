terraform {
  required_version = ">= 1.8.0"

  backend "gcs" {
    bucket = "drew-recipes-tofu-state"
    prefix = "prod"
  }
}
