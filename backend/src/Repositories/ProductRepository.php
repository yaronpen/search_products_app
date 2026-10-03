<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Models\Product;
use PDO;

final class ProductRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<Product> */
    public function all(): array
    {
        $rows = $this->db->query(
            'SELECT id, name, brand, category, subcategory, description, attributes, image_url, price FROM products ORDER BY id'
        );
        return array_map(Product::fromRow(...), $rows->fetchAll());
    }

    /** @return array<string, string> product id => packed embedding vector (products without one are omitted) */
    public function embeddings(): array
    {
        return $this->db
            ->query('SELECT id, embedding FROM products WHERE embedding IS NOT NULL')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** @param iterable<Product> $products */
    public function upsertMany(iterable $products): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO products (id, name, brand, category, subcategory, description, attributes, image_url, price)
             VALUES (:id, :name, :brand, :category, :subcategory, :description, :attributes, :image_url, :price)
             ON DUPLICATE KEY UPDATE name = VALUES(name), brand = VALUES(brand), category = VALUES(category),
               subcategory = VALUES(subcategory), description = VALUES(description), attributes = VALUES(attributes),
               image_url = VALUES(image_url), price = VALUES(price)'
        );
        foreach ($products as $product) {
            $stmt->execute($product->toRow());
        }
    }

    /**
     * @param list<string> $keepIds
     * @return int number of deleted products
     */
    public function deleteExcept(array $keepIds): int
    {
        if ($keepIds === []) {
            return 0; // never wipe the catalog because of an empty input
        }
        $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
        $stmt = $this->db->prepare("DELETE FROM products WHERE id NOT IN ({$placeholders})");
        $stmt->execute($keepIds);
        return $stmt->rowCount();
    }

    /** @return array<string, string> product id => hash of the text its embedding was made from */
    public function embeddingSourceHashes(): array
    {
        return $this->db
            ->query('SELECT id, embedding_src FROM products WHERE embedding IS NOT NULL')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function saveEmbedding(string $id, string $vectorBlob, string $sourceHash): void
    {
        $this->db
            ->prepare('UPDATE products SET embedding = ?, embedding_src = ? WHERE id = ?')
            ->execute([$vectorBlob, $sourceHash, $id]);
    }

    /** @template T @param callable(): T $fn @return T */
    public function transaction(callable $fn): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $fn();
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
