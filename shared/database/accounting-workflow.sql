/* Accounting workflow additions. Existing journals remain posted on upgrade. */
CREATE TABLE IF NOT EXISTS sis_accounting_control (
 id INT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO sis_accounting_control(id) VALUES(1);
CREATE TABLE IF NOT EXISTS sis_accounting_periods (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 start_date DATE NOT NULL, end_date DATE NOT NULL,
 status ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'CLOSED',
 reason VARCHAR(1000) NOT NULL, changed_by INT UNSIGNED NULL, changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY u_sis_period (start_date,end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sis_accounting_suppliers (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL, reference_no VARCHAR(80) NOT NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 UNIQUE KEY u_sis_supplier_ref (reference_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sis_accounting_documents (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 kind ENUM('BILL','DISBURSEMENT','EXPENSE','REIMBURSEMENT','PAYROLL','DEPRECIATION','ADJUSTMENT','REFUND') NOT NULL,
 reference_no VARCHAR(100) NOT NULL, journal_id INT UNSIGNED NOT NULL,
 supplier_id INT UNSIGNED NULL, bill_id INT UNSIGNED NULL,
 registration_id INT UNSIGNED NULL, payment_id INT UNSIGNED NULL,
 amount DECIMAL(12,2) NOT NULL, direction ENUM('CHARGE','LESS') NULL,
 item_id INT UNSIGNED NULL, reversal_item_id INT UNSIGNED NULL, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY u_sis_document_reference (kind,reference_no), UNIQUE KEY u_sis_document_journal (journal_id),
 KEY k_sis_document_reg (registration_id), KEY k_sis_document_bill (bill_id),
 CONSTRAINT fk_sis_document_journal FOREIGN KEY (journal_id) REFERENCES sis_journal(id),
 CONSTRAINT fk_sis_document_supplier FOREIGN KEY (supplier_id) REFERENCES sis_accounting_suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sis_accounting_budgets (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 start_date DATE NOT NULL, end_date DATE NOT NULL, school_year VARCHAR(9) NOT NULL,
 department VARCHAR(80) NOT NULL, fund ENUM('OPERATING','CAPITAL','RESTRICTED') NOT NULL,
 account_id INT UNSIGNED NOT NULL, amount DECIMAL(12,2) NOT NULL,
 created_by INT UNSIGNED NULL,
 UNIQUE KEY u_sis_budget (start_date,end_date,school_year,department,fund,account_id),
 CONSTRAINT fk_sis_budget_account FOREIGN KEY (account_id) REFERENCES sis_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sis_accounting_assets (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 asset_code VARCHAR(40) NOT NULL, name VARCHAR(120) NOT NULL, acquired_date DATE NOT NULL,
 cost DECIMAL(12,2) NOT NULL, residual DECIMAL(12,2) NOT NULL, useful_months INT UNSIGNED NOT NULL,
 asset_account_id INT UNSIGNED NOT NULL, expense_account_id INT UNSIGNED NOT NULL, accumulated_account_id INT UNSIGNED NOT NULL,
 fund ENUM('OPERATING','CAPITAL','RESTRICTED') NOT NULL, department VARCHAR(80) NOT NULL,
 created_by INT UNSIGNED NULL,
 UNIQUE KEY u_sis_asset_code (asset_code),
 CONSTRAINT fk_sis_asset_account FOREIGN KEY (asset_account_id) REFERENCES sis_accounts(id),
 CONSTRAINT fk_sis_asset_expense FOREIGN KEY (expense_account_id) REFERENCES sis_accounts(id),
 CONSTRAINT fk_sis_asset_accumulated FOREIGN KEY (accumulated_account_id) REFERENCES sis_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO sis_accounts(code,name,type,is_cash,description) VALUES
 ('1200','School Equipment','ASSET',0,'Computers, laboratory and school equipment'),
 ('1290','Accumulated Depreciation','ASSET',0,'Credit balance deducted from equipment'),
 ('2200','Accounts Payable - Suppliers','LIABILITY',0,'Unpaid supplier bills'),
 ('2300','Payroll Payable','LIABILITY',0,'Approved payroll awaiting payment'),
 ('5020','Salaries and Wages','EXPENSE',0,'Teacher and staff payroll summaries'),
 ('5030','Depreciation Expense','EXPENSE',0,'Periodic school asset depreciation');
CREATE TABLE IF NOT EXISTS sis_payment_allocations (
 payment_id INT UNSIGNED NOT NULL, item_id INT UNSIGNED NOT NULL,
 category_name VARCHAR(80) NOT NULL, fee_name VARCHAR(100) NOT NULL, amount DECIMAL(12,2) NOT NULL,
 PRIMARY KEY(payment_id,item_id), KEY k_sis_allocation_item(item_id),
 CONSTRAINT fk_sis_allocation_payment FOREIGN KEY(payment_id) REFERENCES sis_payments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sis_refund_allocations (
 document_id INT UNSIGNED NOT NULL, item_id INT UNSIGNED NOT NULL, amount DECIMAL(12,2) NOT NULL,
 PRIMARY KEY(document_id,item_id), KEY k_sis_refund_item(item_id),
 CONSTRAINT fk_sis_refund_document FOREIGN KEY(document_id) REFERENCES sis_accounting_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
