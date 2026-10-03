<?php
declare(strict_types=1);

use App\Controllers\SearchController;
use App\Core\Router;

/**
 * @var Router $router
 * @var SearchController $searchController
 */
$router->get('/api/search', [$searchController, 'search']);
