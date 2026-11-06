-- Also included in INSTALL.sql. Existing installations create this automatically in SIS.
CREATE TABLE IF NOT EXISTS central_temporary_password_expiry (
  account_id BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY(account_id),
  CONSTRAINT fk_central_temp_expiry FOREIGN KEY(account_id) REFERENCES central_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
