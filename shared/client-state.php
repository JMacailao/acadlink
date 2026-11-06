<?php
/** Opaque browser-cache namespace; never contains credentials or authorizes an operation. */
declare(strict_types=1);
function acadlink_client_scope(CentralAccounts $accounts): string {
    $a=$accounts->current();
    if (!$a) return '';
    $permissions=$accounts->permissions((int)$a['id']);
    ksort($permissions);
    return hash('sha256',json_encode([(int)$a['id'],(int)$a['auth_version'],session_id(),$accounts->group((int)$a['id']),$permissions],JSON_THROW_ON_ERROR));
}
