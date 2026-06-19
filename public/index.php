<?php

declare(strict_types=1);

use App\Controllers\MessageController;
use App\Middleware\CorsMiddleware;
use App\Services\MessageService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->add(new CorsMiddleware());
$app->addErrorMiddleware(true, true, true);

$messageService = new MessageService(dirname(__DIR__) . '/data/messages.json');
$messageController = new MessageController($messageService);

$app->get('/health', static function (Request $request, Response $response): Response {
    $payload = [
        'status' => 'ok',
        'service' => 'rest-api',
        'version' => '1.0',
    ];

    $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
});

$app->group('/api/v1.0', function (RouteCollectorProxy $group) use ($messageController): void {
    $group->get('/messages', [$messageController, 'index']);
    $group->map(['HEAD'], '/messages', [$messageController, 'head']);
    $group->post('/messages', [$messageController, 'store']);
    $group->map(['OPTIONS'], '/messages', [$messageController, 'options']);

    $group->get('/messages/{id:\d+}', [$messageController, 'show']);
    $group->map(['HEAD'], '/messages/{id:\d+}', [$messageController, 'head']);
    $group->put('/messages/{id:\d+}', [$messageController, 'replace']);
    $group->patch('/messages/{id:\d+}', [$messageController, 'update']);
    $group->delete('/messages/{id:\d+}', [$messageController, 'destroy']);
    $group->map(['OPTIONS'], '/messages/{id:\d+}', [$messageController, 'options']);
});

$app->get('/openapi.json', static function (Request $request, Response $response): Response {
    $path = __DIR__ . '/openapi.json';

    if (!is_file($path)) {
        $response->getBody()->write(json_encode(['error' => 'OpenAPI spec not found.'], JSON_THROW_ON_ERROR));

        return $response->withStatus(404)->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }

    $response->getBody()->write((string) file_get_contents($path));

    return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
});

$app->run();
