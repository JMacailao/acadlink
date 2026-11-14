<?php
declare(strict_types=1);
/** v63.12: the idle timer's "still here" signal (sis/assets/idle-signout.js). Signing in was checked by the route, which
 *  also records the activity on the sign-in; there is nothing to show. */
http_response_code(204);
header('Cache-Control: no-store');
exit;
