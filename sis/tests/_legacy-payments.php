<?php
/**
 * Included by the suites written before v56. Their flows pay registrations without a payment plan, which v56
 * blocks by default (Settings → cashier_require_plan). The rule itself is tested in tests/v56-features.php.
 */
Db::q("INSERT INTO sis_settings (skey, svalue) VALUES ('cashier_require_plan', '0') ON DUPLICATE KEY UPDATE svalue = '0'");
(function () { self::$all = null; })->bindTo(null, Settings::class)();
