locals {
  name = "mae-storm-recipes"
}

resource "google_service_account" "host" {
  account_id   = "recipes-production"
  display_name = "Recipes production host"
}

resource "google_project_iam_member" "host_secret_access" {
  project = var.project_id
  role    = "roles/secretmanager.secretAccessor"
  member  = "serviceAccount:${google_service_account.host.email}"
}

resource "google_project_iam_member" "host_logs_write" {
  project = var.project_id
  role    = "roles/logging.logWriter"
  member  = "serviceAccount:${google_service_account.host.email}"
}

resource "google_project_iam_member" "host_metrics_write" {
  project = var.project_id
  role    = "roles/monitoring.metricWriter"
  member  = "serviceAccount:${google_service_account.host.email}"
}

resource "google_compute_address" "production" {
  name   = "${local.name}-production"
  region = var.region
}

resource "google_compute_disk" "shared" {
  name = "${local.name}-shared"
  type = "pd-balanced"
  size = 50
  zone = var.zone

  lifecycle {
    prevent_destroy = true
  }
}

resource "google_compute_instance" "production" {
  name         = "${local.name}-production"
  machine_type = "e2-medium"
  zone         = var.zone
  tags         = ["recipes-web"]

  boot_disk {
    initialize_params {
      image = "projects/debian-cloud/global/images/family/debian-13"
      size  = 20
    }
  }

  attached_disk {
    source      = google_compute_disk.shared.id
    device_name = "recipes-shared"
  }

  network_interface {
    network = "default"
    access_config {
      nat_ip = google_compute_address.production.address
    }
  }

  service_account {
    email  = google_service_account.host.email
    scopes = ["cloud-platform"]
  }

  metadata = {
    enable-oslogin        = "TRUE"
    ssh-keys              = "${var.ssh_user}:${var.ssh_public_key}"
    recipes-bootstrap-ref = var.bootstrap_ref
    startup-script        = file("${path.module}/startup.sh")
  }

  lifecycle {
    prevent_destroy = true
  }
}

resource "google_compute_firewall" "web" {
  name    = "${local.name}-web"
  network = "default"
  allow {
    protocol = "tcp"
    ports    = ["80", "443"]
  }
  source_ranges = ["0.0.0.0/0"]
  target_tags   = ["recipes-web"]
}

resource "cloudflare_dns_record" "production" {
  zone_id = var.cloudflare_zone_id
  name    = var.domain
  content = google_compute_address.production.address
  type    = "A"
  proxied = true
  ttl     = 1
}

resource "google_secret_manager_secret" "production" {
  secret_id = "recipes-production"
  replication {
    auto {}
  }
}

resource "google_storage_bucket" "backups" {
  name                        = "${var.project_id}-recipes-backups"
  location                    = var.region
  uniform_bucket_level_access = true
  force_destroy               = false
  versioning { enabled = true }
  lifecycle_rule {
    condition { age = 90 }
    action { type = "Delete" }
  }
}

resource "google_storage_bucket_iam_member" "host_backups" {
  bucket = google_storage_bucket.backups.name
  role   = "roles/storage.objectAdmin"
  member = "serviceAccount:${google_service_account.host.email}"
}

resource "google_monitoring_uptime_check_config" "https" {
  display_name = "Recipes HTTPS"
  timeout      = "10s"
  period       = "60s"
  http_check {
    path         = "/wp-login.php"
    port         = 443
    use_ssl      = true
    validate_ssl = true
  }
  monitored_resource {
    type = "uptime_url"
    labels = {
      host       = var.domain
      project_id = var.project_id
    }
  }
}

resource "google_monitoring_notification_channel" "operator" {
  display_name = "Recipes operator"
  type         = "email"
  labels       = { email_address = var.operator_email }
}

resource "google_monitoring_alert_policy" "uptime" {
  display_name = "Recipes HTTPS unavailable"
  combiner     = "OR"
  conditions {
    display_name = "Uptime failed"
    condition_threshold {
      filter          = "metric.type=\"monitoring.googleapis.com/uptime_check/check_passed\" AND resource.type=\"uptime_url\""
      comparison      = "COMPARISON_LT"
      threshold_value = 1
      duration        = "120s"
      aggregations {
        alignment_period   = "60s"
        per_series_aligner = "ALIGN_NEXT_OLDER"
      }
    }
  }
  notification_channels = [google_monitoring_notification_channel.operator.name]
}

resource "google_monitoring_alert_policy" "disk" {
  display_name = "Recipes disk above 85 percent"
  combiner     = "OR"
  conditions {
    display_name = "Disk utilization"
    condition_threshold {
      filter          = "metric.type=\"agent.googleapis.com/disk/percent_used\" AND resource.type=\"gce_instance\""
      comparison      = "COMPARISON_GT"
      threshold_value = 85
      duration        = "300s"
      aggregations {
        alignment_period   = "60s"
        per_series_aligner = "ALIGN_MEAN"
      }
    }
  }
  notification_channels = [google_monitoring_notification_channel.operator.name]
}

resource "google_monitoring_alert_policy" "backup" {
  display_name = "Recipes backup missing"
  combiner     = "OR"
  conditions {
    display_name = "No successful backup in 30 hours"
    condition_absent {
      filter   = "metric.type=\"logging.googleapis.com/user/recipes_backup_success\" AND resource.type=\"global\""
      duration = "108000s"
    }
  }
  notification_channels = [google_monitoring_notification_channel.operator.name]
}

resource "google_logging_metric" "backup_success" {
  name   = "recipes_backup_success"
  filter = "logName=\"projects/${var.project_id}/logs/recipes-backup\" AND severity=\"INFO\" AND resource.type=\"global\""
  metric_descriptor {
    metric_kind = "DELTA"
    value_type  = "INT64"
  }
}
