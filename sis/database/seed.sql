/* ========================================================================== */
/* SIS — starting data (after schema.sql; safe to run again: existing rows are kept) */
/* First administrator: username admin, password ChangeMe!2026 (change at first sign-in). */
/* ========================================================================== */
SET NAMES utf8mb4;

INSERT IGNORE INTO sis_users (username, full_name, password_hash, role, must_change_password) VALUES
 ('admin', 'System Administrator', '$2y$10$5s9b9b2q08mb8Nm5s5g5du6ANxeV/7HWl4b1c9j/wT6dU8.si7/86', 'ADMIN', 1);

INSERT IGNORE INTO sis_sequences (name, next_value) VALUES ('admission', 1), ('registration', 1), ('receipt', 1);

INSERT IGNORE INTO sis_or_series (code, description, prefix, digits, next_number, template, is_active, auto_number, allow_edit, is_default) VALUES
 ('OR1', 'Official Receipt Series One', 'OR-', 7, 1, 'STANDARD', 1, 1, 1, 1);

INSERT IGNORE INTO sis_fee_categories (name, description, sort_order) VALUES
 ('Tuition', 'Tuition fees', 10),
 ('Miscellaneous', 'Registration, library, ID and other school fees', 20),
 ('Laboratory', 'Laboratory and computer fees', 30),
 ('Other Fees', 'Other authorized charges', 40);
