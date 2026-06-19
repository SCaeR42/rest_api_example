<?php

declare(strict_types=1);

namespace App;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'Messages REST API',
    version: '1.0.0',
    description: 'REST API для управления сообщениями'
)]
#[OA\Schema(
    schema: 'Message',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'content', type: 'string', example: 'Hello World'),
        new OA\Property(property: 'author', type: 'string', example: 'anonymous'),
        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time'),
    ]
)]
class OpenApiSpec
{
    /**
     * Генерирует массив атрибутов OA\Server из переменной окружения OPENAPI_SERVERS.
     *
     * Формат OPENAPI_SERVERS — JSON-массив объектов:
     *   [{"url": "http://...", "description": "..."}, ...]
     *
     * @return OA\Server[]
     */
    public static function getServers(): array
    {
        $json = $_ENV['OPENAPI_SERVERS'] ?? $_SERVER['OPENAPI_SERVERS'] ?? '';

        if ($json === '') {
            return [
                new OA\Server(
                    url: 'http://127.0.0.1:8080/api/v1.0',
                    description: 'Local development server'
                ),
            ];
        }

        $servers = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($servers)) {
            throw new \RuntimeException('OPENAPI_SERVERS must decode to an array.');
        }

        return array_map(
            static fn(array $s): OA\Server => new OA\Server(
                url: $s['url'] ?? throw new \RuntimeException('Each server must have a "url" key.'),
                description: $s['description'] ?? null,
            ),
            $servers,
        );
    }
}
