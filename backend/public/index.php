<?php
declare(strict_types=1);

/** Front controller: every /api/* request is routed here by the web server. */

use App\Core\App;
use App\Core\JsonResponse;
use App\Core\Request;

try {
    /** @var App $app */
    $app = require __DIR__ . '/../bootstrap/app.php';
    $response = $app->router->dispatch(Request::fromGlobals());
} catch (\Throwable $e) {
    // Includes bootstrap failures such as the database being unreachable
    error_log('Unhandled error: ' . $e);
    $response = JsonResponse::error(500, 'server_error', 'אירעה שגיאה בחיפוש. נסו שוב בעוד רגע.');
}
$response->send();
