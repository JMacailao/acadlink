<?php
/** v62 A student photo (?r=photo&id=): staff who see students or admissions, the student, and their linked parents. */
declare(strict_types=1);
BinaryResponse::begin(); // v63.21: photos of a list download side by side; no after-page jobs
[$mime, $bytes] = StudentPhoto::open((int) ($_GET['id'] ?? 0));
// a photo id always names the same bytes (a new photo is a new row), so the browser may keep it
BinaryResponse::send($mime, $bytes, true, 86400, ['Content-Disposition' => 'inline', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
