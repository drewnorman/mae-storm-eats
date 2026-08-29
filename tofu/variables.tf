variable "project_id" {
  type        = string
  description = "GCP project that owns the production host and backups."
}

variable "region" {
  type    = string
  default = "us-central1"
}

variable "zone" {
  type    = string
  default = "us-central1-a"
}

variable "domain" {
  type    = string
  default = "recipes.maestorm.com"
}

variable "cloudflare_zone_id" {
  type = string
}

variable "cloudflare_api_token" {
  type      = string
  sensitive = true
}

variable "operator_email" {
  type        = string
  description = "Email address for uptime, backup, and capacity alerts."
}

variable "ssh_user" {
  type    = string
  default = "recipes"
}

variable "ssh_public_key" {
  type      = string
  sensitive = true
}
