<?php
declare(strict_types=1);

namespace Club\Core;

final class Session
{
    /** @param array<string, string> $env */
    public static function start(array $env): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        session_name($env['APP_SESSION_NAME'] ?? 'club_da_venda_php');
        session_set_cookie_params([
            'httponly' => true,
            'secure' => ($env['APP_ENV'] ?? 'local') === 'production',
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        session_start();
    }
}
