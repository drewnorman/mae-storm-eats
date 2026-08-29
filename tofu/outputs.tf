output "production_ip" { value = google_compute_address.production.address }
output "backup_bucket" { value = google_storage_bucket.backups.name }
output "instance_name" { value = google_compute_instance.production.name }
output "secret_name" { value = google_secret_manager_secret.production.secret_id }
