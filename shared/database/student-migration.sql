CREATE TABLE IF NOT EXISTS sis_migration_batches (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT,
 kind ENUM('BALANCES','GRADES') NOT NULL,
 file_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 source_name VARCHAR(191) NOT NULL,
 status ENUM('DRAFT','APPROVED','IMPORTED','REVERSED','DISCARDED') NOT NULL DEFAULT 'DRAFT',
 input_json JSON NOT NULL,
 review_json JSON NOT NULL,
 approved_hash CHAR(64) CHARACTER SET ascii NULL,
 equity_account_id INT UNSIGNED NULL,
 created_by INT UNSIGNED NOT NULL,
 reviewed_by INT UNSIGNED NULL,
 review_reason VARCHAR(1000) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 imported_at DATETIME NULL,
 PRIMARY KEY(id), UNIQUE KEY u_migration_file(kind,file_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sis_migration_payment_items (
 payment_id INT UNSIGNED NOT NULL,
 item_id INT UNSIGNED NOT NULL,
 amount DECIMAL(12,2) NOT NULL,
 PRIMARY KEY(payment_id,item_id),
 CONSTRAINT fk_migration_allocation_payment FOREIGN KEY(payment_id) REFERENCES sis_payments(id),
 CONSTRAINT fk_migration_allocation_item FOREIGN KEY(item_id) REFERENCES sis_registration_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sis_migration_claims (
 key_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 batch_id INT UNSIGNED NOT NULL,
 source_row INT UNSIGNED NOT NULL,
 PRIMARY KEY(key_hash), KEY k_migration_claim_batch(batch_id),
 CONSTRAINT fk_migration_claim_batch FOREIGN KEY(batch_id) REFERENCES sis_migration_batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sis_migration_targets (
 batch_id INT UNSIGNED NOT NULL,
 kind ENUM('REGISTRATION','COMPLETION') NOT NULL,
 target_id VARCHAR(191) NOT NULL,
 PRIMARY KEY(batch_id,kind,target_id),
 CONSTRAINT fk_migration_target_batch FOREIGN KEY(batch_id) REFERENCES sis_migration_batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
