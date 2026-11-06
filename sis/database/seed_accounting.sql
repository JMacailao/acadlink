-- Default chart of accounts and account mappings (safe to run more than once).
INSERT IGNORE INTO sis_accounts (code, name, type, is_cash, description) VALUES
 ('1010', 'Cash on Hand', 'ASSET', 1, 'Cashier collections in cash and checks not yet deposited'),
 ('1020', 'Cash in Bank', 'ASSET', 1, 'School bank account: deposits, card and bank transfer payments'),
 ('1030', 'E-Wallet Collections (GCash / Maya)', 'ASSET', 1, 'Payments received through GCash or Maya'),
 ('1100', 'Accounts Receivable - Students', 'ASSET', 0, 'What students owe on their assessments'),
 ('2100', 'Student Deposits and Advances', 'LIABILITY', 0, 'Money received before it is earned'),
 ('3000', 'Fund Balance', 'EQUITY', 0, 'Opening balances and accumulated surplus'),
 ('4010', 'Tuition Fees', 'REVENUE', 0, NULL),
 ('4020', 'Miscellaneous Fees', 'REVENUE', 0, NULL),
 ('4030', 'Laboratory Fees', 'REVENUE', 0, NULL),
 ('4090', 'Other School Fees', 'REVENUE', 0, 'Other fees and charges added to an assessment'),
 ('4900', 'Scholarships and Discounts', 'CONTRA_REVENUE', 0, 'Less on the fees: scholarships, discounts and other less'),
 ('5010', 'Operating Expenses', 'EXPENSE', 0, 'Expenses entered by hand in the journal');
INSERT IGNORE INTO sis_account_map (map_key, account_id)
 SELECT k, (SELECT id FROM sis_accounts WHERE code = c) FROM (
   SELECT 'ar' k, '1100' c UNION ALL SELECT 'default_revenue', '4090' UNION ALL SELECT 'default_discount', '4900'
   UNION ALL SELECT 'adjust_charge', '4090' UNION ALL SELECT 'adjust_less', '4900'
   UNION ALL SELECT 'method:CASH', '1010' UNION ALL SELECT 'method:CHECK', '1010' UNION ALL SELECT 'method:CARD', '1020'
   UNION ALL SELECT 'method:BANK', '1020' UNION ALL SELECT 'method:GCASH', '1030' UNION ALL SELECT 'method:MAYA', '1030'
 ) m WHERE (SELECT id FROM sis_accounts WHERE code = c) IS NOT NULL;
INSERT IGNORE INTO sis_account_map (map_key, account_id)
 SELECT CONCAT('cat:', c.id), a.id FROM sis_fee_categories c JOIN sis_accounts a ON a.code = CASE
   WHEN c.name LIKE 'Tuition%' THEN '4010' WHEN c.name LIKE 'Misc%' THEN '4020' WHEN c.name LIKE 'Lab%' THEN '4030' ELSE '4090' END;
