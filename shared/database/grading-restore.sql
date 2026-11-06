-- Private staging for atomic Grading Excel restore. No live grading rows change during upload.
CREATE TABLE IF NOT EXISTS grading_restore_jobs (
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_account_id BIGINT UNSIGNED NOT NULL,
 actor_auth_version BIGINT UNSIGNED NOT NULL,
 policy_version BIGINT UNSIGNED NOT NULL,
 session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 restore_mode ENUM('merge','replace') NOT NULL,
 expected_counts JSON NOT NULL,
 expected_chunks INT UNSIGNED NOT NULL,
 received_chunks JSON NOT NULL,
 total_rows INT UNSIGNED NOT NULL,
 received_rows INT UNSIGNED NOT NULL DEFAULT 0,
 status ENUM('STAGING','COMMITTED') NOT NULL DEFAULT 'STAGING',
 result JSON NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at DATETIME NOT NULL,
 PRIMARY KEY(token_hash), KEY k_grading_restore_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
CREATE TABLE IF NOT EXISTS grading_restore_records (
 job_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 col VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 id VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
 data JSON NOT NULL,
 PRIMARY KEY(job_hash,col,id),
 CONSTRAINT fk_grading_restore_job FOREIGN KEY(job_hash) REFERENCES grading_restore_jobs(token_hash) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
