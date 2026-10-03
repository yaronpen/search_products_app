<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/** Small key/value settings table (currently the catalog version used for cache invalidation). */
final class MetaRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function get(string $key): ?string
    {
        $stmt = $this->db->prepare('SELECT v FROM meta WHERE k = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    public function set(string $key, string $value): void
    {
        $this->db->prepare('REPLACE INTO meta (k, v) VALUES (?, ?)')->execute([$key, $value]);
    }
}
