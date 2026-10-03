<?php
declare(strict_types=1);

/**
 * Autoloading and dependency wiring, shared by the web front controller, the CLI import
 * and the tests. Objects are built by hand, in dependency order: repositories, then
 * services, then controllers and routes.
 */

use App\Clients\OpenAiEmbeddingsClient;
use App\Controllers\SearchController;
use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Core\Router;
use App\Repositories\MetaRepository;
use App\Repositories\ProductRepository;
use App\Repositories\QueryEmbeddingRepository;
use App\Repositories\SchemaRepository;
use App\Services\CatalogImportService;
use App\Services\CatalogIndexService;
use App\Services\EmbeddingService;
use App\Services\SearchService;

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// Repositories
$db = Database::connection();
$productRepository = new ProductRepository($db);
$metaRepository = new MetaRepository($db);

// Services
$apiKey = Config::get('OPENAI_API_KEY');
// Without a key semantic search is off. Web requests fail fast (then fall back to
// keyword search); the CLI import embeds the whole catalog, so it can wait longer.
$embeddingClient = $apiKey === null ? null : new OpenAiEmbeddingsClient(
    $apiKey,
    Config::require('OPENAI_EMBEDDINGS_URL'),
    Config::require('OPENAI_EMBEDDINGS_MODEL'),
    Config::requirePositiveInt('OPENAI_EMBEDDINGS_DIMENSIONS'),
    PHP_SAPI === 'cli' ? 60.0 : Config::requirePositiveFloat('OPENAI_WEB_TIMEOUT_SECONDS'),
);
$embeddingService = new EmbeddingService($embeddingClient, new QueryEmbeddingRepository($db));
$catalogIndexService = new CatalogIndexService($productRepository, $metaRepository);
$searchService = new SearchService($catalogIndexService, $embeddingService);
$importService = new CatalogImportService(
    new SchemaRepository($db),
    $productRepository,
    $embeddingService,
    $catalogIndexService,
    fn(string $line) => fwrite(STDERR, $line . "\n"),
);

// Controllers & routes
$searchController = new SearchController($searchService);
$router = new Router();
require __DIR__ . '/../routes/api.php';

return new App($router, $searchService, $importService, $catalogIndexService, $embeddingService);
