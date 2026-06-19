<?php

declare(strict_types=1);

namespace App;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'Messages REST API',
    version: '1.0.0',
    description: 'REST API для управления сообщениями'
)]
#[OA\Server(url: '/api/v1.0')]
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
}
