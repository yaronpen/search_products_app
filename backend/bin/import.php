<?php
declare(strict_types=1);

/**
 * Loads the product CSV into MySQL and computes embeddings.
 *
 *   php bin/import.php [path/to/catalog.csv] [--skip-embeddings]
 */

use App\Core\App;

/** @var App $app */
$app = require __DIR__ . '/../bootstrap/app.php';

$args = array_slice($argv, 1);
$path = current(array_filter($args, fn($a) => !str_starts_with($a, '--')))
    ?: __DIR__ . '/../../data/catalog_no_prices.csv';

try {
    $app->importer->import($path, withEmbeddings: !in_array('--skip-embeddings', $args, true));
    fwrite(STDERR, "Done.\n");
} catch (\Throwable $e) {
    fwrite(STDERR, 'Import failed: ' . $e->getMessage() . "\n");
    exit(1);
}
