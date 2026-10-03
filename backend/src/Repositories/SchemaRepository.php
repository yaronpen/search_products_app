<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SchemaRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** Applies the idempotent schema (CREATE TABLE IF NOT EXISTS ...). */
    public function migrate(): void
    {
        $this->db->exec(file_get_contents(__DIR__ . '/../../sql/001_schema.sql'));
    }
}
