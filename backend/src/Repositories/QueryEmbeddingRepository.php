<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/** Persistent cache of query embeddings, so a repeated search never calls the API twice. */
final class QueryEmbeddingRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function find(string $hash): ?string
    {
        $stmt = $this->db->prepare('SELECT embedding FROM query_embeddings WHERE query_hash = ?');
        $stmt->execute([$hash]);
        $blob = $stmt->fetchColumn();
        return $blob === false ? null : $blob;
    }

    public function save(string $hash, string $query, string $vectorBlob): void
    {
        $this->db
            ->prepare('INSERT IGNORE INTO query_embeddings (query_hash, query, embedding) VALUES (?, ?, ?)')
            ->execute([$hash, mb_substr($query, 0, 255), $vectorBlob]);
    }
}
