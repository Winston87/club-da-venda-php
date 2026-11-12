<?php
declare(strict_types=1);

namespace Club\Core;

use PDO;
use PDOException;

final class Database
{
    private ?PDO $connection = null;

    /** @param array<string, string> $env */
    public function __construct(private readonly array $env) {}

    public function connection(): PDO
    {
        if ($this->connection instanceof PDO) return $this->connection;
        $url = $this->env['DATABASE_URL'] ?? '';
        if ($url === '') throw new PDOException('DATABASE_URL não configurada.');
        $parts = parse_url($url);
        if (!$parts || !isset($parts['host'], $parts['user'], $parts['pass'])) throw new PDOException('DATABASE_URL inválida.');
        parse_str($parts['query'] ?? '', $query);
        $port = $parts['port'] ?? 5432;
        $name = ltrim($parts['path'] ?? '/postgres', '/');
        $dsn = "pgsql:host={$parts['host']};port={$port};dbname={$name};sslmode=" . ($query['sslmode'] ?? 'require');
        return $this->connection = new PDO($dsn, urldecode($parts['user']), urldecode($parts['pass']), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
