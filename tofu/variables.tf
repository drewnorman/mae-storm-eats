variable "project_id" {
  type        = string
  description = "GCP project that owns the production host and backups."
}

variable "bootstrap_ref" {
  type        = string
  description = "Approved immutable Git commit used to install host operations files on first boot."

  validation {
    condition     = can(regex("^[0-9a-fA-F]{40}$", var.bootstrap_ref))
    error_message = "bootstrap_ref must be a full 40-character Git commit SHA."
  }
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
  default = "eats.maestorm.com"
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
