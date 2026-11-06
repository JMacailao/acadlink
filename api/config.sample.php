<?php
// ==========================================================
// Copy this file to config.php and fill in your MySQL details.
// (Hostinger / cPanel: Databases → MySQL Databases shows them.)
// config.php is never shown to visitors (see .htaccess).
// ==========================================================
return [
  "db_host" => "localhost",
  "db_port" => 3306,
  "db_name" => "acadlink",
  "db_user" => "root",
  "db_pass" => "",
  // Name of the sign-in cookie (any short name)
  "session_name" => "GSSESSION",

  // Only if the website and api/ are on different addresses, list the website's host here,
  // e.g. ["www.yourschool.edu.ph"]. Leave empty when they are on the same site (normal).
  "allowed_hosts" => [],
];
