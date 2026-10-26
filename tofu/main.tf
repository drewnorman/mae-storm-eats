locals {
  name        = "mae-storm-eats"
  wif_pool_id = "github-actions"
}

data "google_project" "current" {
  project_id = var.project_id
}

resource "google_project_service" "iap" {
  project            = var.project_id
  service            = "iap.googleapis.com"
  disable_on_destroy = false
}

# The shared project already owns the GitHub Actions pool. This app owns only
# its provider and deploy identity, so shared-infra's trust remains unchanged.
resource "google_iam_workload_identity_pool_provider" "github_deploy" {
  workload_identity_pool_id          = local.wif_pool_id
  workload_identity_pool_provider_id = local.name
  display_name                       = "Mae Storm Eats production deploy"

  attribute_mapping = {
    "google.subject"       = "assertion.sub"
    "attribute.repository" = "assertion.repository"
  }

  attribute_condition = "assertion.repository == 'drewnorman/mae-storm-eats' && assertion.ref == 'refs/heads/master' && assertion.environment == 'production'"

  oidc {
    issuer_uri = "https://token.actions.githubusercontent.com"
  }
}

resource "google_service_account" "deploy" {
  account_id   = "mae-storm-eats-deploy"
  display_name = "Mae Storm Eats production deploy"
}

resource "google_service_account_iam_member" "deploy_wif" {
  service_account_id = google_service_account.deploy.name
  role               = "roles/iam.workloadIdentityUser"
  member             = "principalSet://iam.googleapis.com/projects/${data.google_project.current.number}/locations/global/workloadIdentityPools/${local.wif_pool_id}/attribute.repository/drewnorman/mae-storm-eats"
}

# gcloud compute ssh needs project discovery, while OS Login and IAP are
# limited to this app's VM below.
resource "google_project_iam_member" "deploy_compute_viewer" {
  project = var.project_id
  role    = "roles/compute.viewer"
  member  = "serviceAccount:${google_service_account.deploy.email}"
}

resource "google_service_account" "host" {
  account_id   = "mae-storm-eats-production"
  display_name = "Mae Storm Eats production host"
}

resource "google_service_account_iam_member" "deploy_host_act_as" {
  service_account_id = google_service_account.host.name
  role               = "roles/iam.serviceAccountUser"
  member             = "serviceAccount:${google_service_account.deploy.email}"
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
  tags         = ["mae-storm-eats-web"]

  boot_disk {
    initialize_params {
      image = "projects/debian-cloud/global/images/family/debian-13"
      size  = 20
    }
  }

  attached_disk {
    source      = google_compute_disk.shared.id
    device_name = "mae-storm-eats-shared"
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
    enable-oslogin               = "TRUE"
    mae-storm-eats-bootstrap-ref = var.bootstrap_ref
    startup-script               = file("${path.module}/startup.sh")
  }

  lifecycle {
    prevent_destroy = true
  }
}

resource "google_compute_instance_iam_member" "deploy_os_admin" {
  project       = var.project_id
  zone          = google_compute_instance.production.zone
  instance_name = google_compute_instance.production.name
  role          = "roles/compute.osAdminLogin"
  member        = "serviceAccount:${google_service_account.deploy.email}"
}

resource "google_iap_tunnel_instance_iam_member" "deploy_iap_ssh" {
  project  = var.project_id
  zone     = google_compute_instance.production.zone
  instance = google_compute_instance.production.name
  role     = "roles/iap.tunnelResourceAccessor"
  member   = "serviceAccount:${google_service_account.deploy.email}"

  depends_on = [google_project_service.iap]
}

resource "google_compute_firewall" "web" {
  name    = "${local.name}-web"
  network = "default"
  allow {
    protocol = "tcp"
    ports    = ["80", "443"]
  }
  source_ranges = ["0.0.0.0/0"]
  target_tags   = ["mae-storm-eats-web"]
}

resource "google_compute_firewall" "ssh_iap" {
  name     = "${local.name}-ssh-iap"
  network  = "default"
  priority = 900

  allow {
    protocol = "tcp"
    ports    = ["22"]
  }

  source_ranges = ["35.235.240.0/20"]
  target_tags   = ["mae-storm-eats-web"]
}

# The default VPC may have a broad default-allow-ssh rule. Explicitly deny
# direct SSH at a lower precedence than the IAP allow above.
resource "google_compute_firewall" "ssh_direct_deny" {
  name      = "${local.name}-deny-direct-ssh"
  network   = "default"
  priority  = 1000
  direction = "INGRESS"

  deny {
    protocol = "tcp"
    ports    = ["22"]
  }

  source_ranges = ["0.0.0.0/0"]
  target_tags   = ["mae-storm-eats-web"]
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
  secret_id = "mae-storm-eats-production"
  replication {
    auto {}
  }
}

resource "google_storage_bucket" "backups" {
  name                        = "${var.project_id}-mae-storm-eats-backups"
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
  display_name = "Mae Storm Eats HTTPS"
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
  display_name = "Mae Storm Eats operator"
  type         = "email"
  labels       = { email_address = var.operator_email }
}

resource "google_monitoring_alert_policy" "uptime" {
  display_name = "Mae Storm Eats HTTPS unavailable"
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
  display_name = "Mae Storm Eats disk above 85 percent"
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
  display_name = "Mae Storm Eats backup missing"
  combiner     = "OR"
  conditions {
    display_name = "No successful backup in 23.5 hours"
    condition_absent {
      filter   = "metric.type=\"logging.googleapis.com/user/mae_storm_eats_backup_success\" AND resource.type=\"global\""
      duration = "84600s"
    }
  }
  notification_channels = [google_monitoring_notification_channel.operator.name]
  depends_on            = [google_logging_metric.backup_success]
}

resource "google_logging_metric" "backup_success" {
  name   = "mae_storm_eats_backup_success"
  filter = "logName=\"projects/${var.project_id}/logs/mae-storm-eats-backup\" AND severity=\"INFO\" AND resource.type=\"global\""
  metric_descriptor {
    metric_kind = "DELTA"
    value_type  = "INT64"
  }
}
