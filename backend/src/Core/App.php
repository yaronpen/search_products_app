<?php
declare(strict_types=1);

namespace App\Core;

use App\Services\CatalogImportService;
use App\Services\CatalogIndexService;
use App\Services\EmbeddingService;
use App\Services\SearchService;

/** The wired-up application, as built by bootstrap/app.php. Entry points pick what they need. */
final class App
{
    public function __construct(
        public readonly Router $router,
        public readonly SearchService $search,
        public readonly CatalogImportService $importer,
        public readonly CatalogIndexService $catalogIndex,
        public readonly EmbeddingService $embeddings,
    ) {
    }
}
