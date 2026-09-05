-- Production Readiness Pilot C: minimized field Web Vitals storage.
-- No IP, user-agent, URL, referrer, cookie, session, account, contact or payload fields.

CREATE TABLE IF NOT EXISTS production_rum_samples (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  metric ENUM('LCP','INP','CLS') NOT NULL,
  value DECIMAL(14,4) UNSIGNED NOT NULL,
  route_group VARCHAR(64) NOT NULL,
  build_id VARCHAR(64) NOT NULL,
  form_factor ENUM('mobile','desktop') NOT NULL,
  created_at_utc DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_production_rum_window (created_at_utc, metric, route_group, form_factor),
  KEY idx_production_rum_build (build_id, created_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
