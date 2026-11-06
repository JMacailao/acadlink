<?php
declare(strict_types=1);
/** @var bool $isPost Set by index.php: true when a form was submitted (POST). */
if ($isPost) { Audit::log('logout', 'user', Auth::id()); Auth::logout(); }
redirect('login');
