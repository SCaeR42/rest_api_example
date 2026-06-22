<?php

declare(strict_types=1);

namespace App\Controllers;

use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HealthController
{
    #[OA\Get(
        path: '/health',
        summary: 'Проверка работоспособности API',
        tags: ['Health'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Сервис работает',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'ok'),
                        new OA\Property(property: 'service', type: 'string', example: 'rest-api'),
                        new OA\Property(property: 'version', type: 'string', example: '1.0'),
                    ],
                    type: 'object'
                )
            ),
        ]
    )]
    public function __invoke(Request $request, Response $response): Response
    {
        $payload = [
            'status' => 'ok',
            'service' => 'rest-api',
            'version' => '1.1',
        ];

        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }
}
