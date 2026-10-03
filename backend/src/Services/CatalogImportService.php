<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Repositories\ProductRepository;
use App\Repositories\SchemaRepository;
use App\Support\Vector;

/**
 * Loads the product CSV into MySQL and computes embeddings.
 *
 * Idempotent: rows are upserted by id, products missing from the CSV are removed,
 * and only products whose text changed are re-embedded.
 */
final class CatalogImportService
{
    public const REQUIRED_COLUMNS = ['id', 'name', 'brand', 'category', 'subcategory', 'description', 'attributes', 'image_url'];
    private const EMBED_BATCH = 100;

    /** @var callable(string): void */
    private $log;

    public function __construct(
        private readonly SchemaRepository $schema,
        private readonly ProductRepository $products,
        private readonly EmbeddingService $embeddings,
        private readonly CatalogIndexService $catalogIndex,
        ?callable $log = null,
    ) {
        $this->log = $log ?? static function (string $line): void {};
    }

    public function import(string $csvPath, bool $withEmbeddings = true): void
    {
        $this->schema->migrate();

        [$products, $skipped, $hasPrice] = $this->readCsv($csvPath);
        ($this->log)(sprintf('Read %d products (%d skipped)%s', count($products), $skipped, $hasPrice ? '' : ', prices estimated'));

        $removed = $this->products->transaction(function () use ($products): int {
            $this->products->upsertMany($products);
            return $this->products->deleteExcept(array_keys($products));
        });
        ($this->log)("Removed {$removed} products no longer in the CSV");

        if (!$withEmbeddings) {
            ($this->log)('Skipping embeddings');
        } elseif (!$this->embeddings->isEnabled()) {
            ($this->log)('OPENAI_API_KEY not set: skipping embeddings, search will run in keyword-only mode');
        } else {
            $this->embedChanged($products);
        }

        $this->catalogIndex->bumpVersion();
    }

    /**
     * @return array{array<string, Product>, int, bool} products by id, skipped count, whether the CSV had prices
     */
    private function readCsv(string $path): array
    {
        $handle = @fopen($path, 'r') ?: throw new \RuntimeException("Cannot open {$path}");
        $header = fgetcsv($handle, escape: '') ?: throw new \RuntimeException('CSV is empty');
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]); // strip UTF-8 BOM
        if ($missing = array_diff(self::REQUIRED_COLUMNS, $header)) {
            throw new \RuntimeException('CSV is missing columns: ' . implode(', ', $missing));
        }
        $hasPrice = in_array('price', $header, true);

        $products = [];
        $skipped = 0;
        $line = 1;
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $line++;
            if ($row === [null]) {
                continue; // blank line
            }
            if (count($row) !== count($header)) {
                ($this->log)("Line {$line}: expected " . count($header) . ' fields, got ' . count($row) . ', skipped');
                $skipped++;
                continue;
            }
            $p = array_map('trim', array_combine($header, $row));
            if ($p['id'] === '' || $p['name'] === '') {
                ($this->log)("Line {$line}: missing id or name, skipped");
                $skipped++;
                continue;
            }
            $p['price'] = $hasPrice && is_numeric($p['price'])
                ? (float) $p['price']
                : PriceEstimator::estimate($p['id'], $p['name'], $p['subcategory']);
            $products[$p['id']] = Product::fromRow($p);
        }
        fclose($handle);

        return [$products, $skipped, $hasPrice];
    }

    /** @param array<string, Product> $products */
    private function embedChanged(array $products): void
    {
        $existing = $this->products->embeddingSourceHashes();
        $todo = [];
        foreach ($products as $id => $product) {
            $text = $this->embeddings->productText($product);
            $hash = $this->embeddings->hash($text);
            if (($existing[$id] ?? null) !== $hash) {
                $todo[$id] = [$text, $hash];
            }
        }
        ($this->log)(sprintf('Embedding %d products (%d unchanged)', count($todo), count($products) - count($todo)));

        foreach (array_chunk($todo, self::EMBED_BATCH, preserve_keys: true) as $batch) {
            $vectors = $this->embeddings->embedMany(array_column(array_values($batch), 0));
            $i = 0;
            foreach ($batch as $id => [, $hash]) {
                $this->products->saveEmbedding((string) $id, Vector::pack($vectors[$i++]), $hash);
            }
            ($this->log)(sprintf('  embedded %d', count($batch)));
        }
    }
}
