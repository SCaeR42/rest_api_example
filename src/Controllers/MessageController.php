<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\MessageService;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final readonly class MessageController
{
    public function __construct(private MessageService $messageService)
    {
    }

    #[OA\Get(
        path: '/messages',
        summary: 'Получить список всех сообщений',
        tags: ['Messages'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Успешный запрос',
                headers: [
                    new OA\Header(header: 'X-Total-Count', description: 'Общее количество сообщений', schema: new OA\Schema(type: 'integer')),
                ],
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Message')),
                        new OA\Property(property: 'meta', properties: [
                            new OA\Property(property: 'count', type: 'integer'),
                        ], type: 'object'),
                    ],
                    type: 'object'
                )
            ),
        ]
    )]
    public function index(Request $request, Response $response): Response
    {
        $messages = $this->messageService->all();

        return $this->json($response, [
            'data' => $messages,
            'meta' => [
                'count' => count($messages),
            ],
        ], 200, ['X-Total-Count' => (string) count($messages)]);
    }

    #[OA\Get(
        path: '/messages/{id}',
        summary: 'Получить сообщение по ID',
        tags: ['Messages'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Успешный запрос',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Message'),
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(response: 404, description: 'Сообщение не найдено'),
        ]
    )]
    public function show(Request $request, Response $response, array $args): Response
    {
        $message = $this->messageService->find((int) $args['id']);

        if ($message === null) {
            return $this->error($response, 'Сообщение не найдено.', 404);
        }

        return $this->json($response, ['data' => $message], 200, ['X-Resource-Id' => (string) $message['id']]);
    }

    #[OA\Post(
        path: '/messages',
        summary: 'Создать новое сообщение',
        tags: ['Messages'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['content'],
                properties: [
                    new OA\Property(property: 'content', type: 'string', description: 'Текст сообщения'),
                    new OA\Property(property: 'author', type: 'string', description: 'Автор сообщения', default: 'anonymous'),
                    new OA\Property(property: 'status', type: 'string', enum: ['new', 'processing', 'in_progress', 'completed'], description: 'Статус сообщения', default: 'new'),
                ],
                type: 'object'
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Сообщение создано',
                headers: [
                    new OA\Header(header: 'Location', description: 'URL созданного ресурса', schema: new OA\Schema(type: 'string')),
                ],
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Message'),
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(response: 422, description: 'Ошибка валидации'),
        ]
    )]
    public function store(Request $request, Response $response): Response
    {
        try {
            $message = $this->messageService->create($this->body($request));
        } catch (InvalidArgumentException $exception) {
            return $this->error($response, $exception->getMessage(), 422);
        }

        return $this->json(
            $response,
            ['data' => $message],
            201,
            ['Location' => '/api/v1.0/messages/' . $message['id']]
        );
    }

    #[OA\Put(
        path: '/messages/{id}',
        summary: 'Полностью заменить сообщение',
        tags: ['Messages'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['content'],
                properties: [
                    new OA\Property(property: 'content', type: 'string', description: 'Текст сообщения'),
                    new OA\Property(property: 'author', type: 'string', description: 'Автор сообщения'),
                    new OA\Property(property: 'status', type: 'string', enum: ['new', 'processing', 'in_progress', 'completed'], description: 'Статус сообщения'),
                ],
                type: 'object'
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Сообщение обновлено',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Message'),
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(response: 404, description: 'Сообщение не найдено'),
            new OA\Response(response: 422, description: 'Ошибка валидации'),
        ]
    )]
    public function replace(Request $request, Response $response, array $args): Response
    {
        try {
            $message = $this->messageService->replace((int) $args['id'], $this->body($request));
        } catch (InvalidArgumentException $exception) {
            return $this->error($response, $exception->getMessage(), 422);
        }

        if ($message === null) {
            return $this->error($response, 'Сообщение не найдено.', 404);
        }

        return $this->json($response, ['data' => $message], 200, ['X-Resource-Id' => (string) $message['id']]);
    }

    #[OA\Patch(
        path: '/messages/{id}',
        summary: 'Частично обновить сообщение',
        tags: ['Messages'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'content', type: 'string', description: 'Текст сообщения'),
                    new OA\Property(property: 'author', type: 'string', description: 'Автор сообщения'),
                    new OA\Property(property: 'status', type: 'string', enum: ['new', 'processing', 'in_progress', 'completed'], description: 'Статус сообщения'),
                ],
                type: 'object'
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Сообщение обновлено',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Message'),
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(response: 404, description: 'Сообщение не найдено'),
            new OA\Response(response: 422, description: 'Ошибка валидации'),
        ]
    )]
    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $message = $this->messageService->update((int) $args['id'], $this->body($request));
        } catch (InvalidArgumentException $exception) {
            return $this->error($response, $exception->getMessage(), 422);
        }

        if ($message === null) {
            return $this->error($response, 'Сообщение не найдено.', 404);
        }

        return $this->json($response, ['data' => $message], 200, ['X-Resource-Id' => (string) $message['id']]);
    }

    #[OA\Delete(
        path: '/messages/{id}',
        summary: 'Удалить сообщение',
        tags: ['Messages'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Сообщение удалено'),
            new OA\Response(response: 404, description: 'Сообщение не найдено'),
        ]
    )]
    public function destroy(Request $request, Response $response, array $args): Response
    {
        if (!$this->messageService->delete((int) $args['id'])) {
            return $this->error($response, 'Сообщение не найдено.', 404);
        }

        return $response->withStatus(204);
    }

    #[OA\Options(
        path: '/messages',
        summary: 'Получить список доступных HTTP-методов для коллекции сообщений',
        tags: ['Messages'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Список доступных методов',
                headers: [
                    new OA\Header(header: 'Allow', description: 'Список разрешённых HTTP-методов', schema: new OA\Schema(type: 'string')),
                ]
            ),
        ]
    )]
    #[OA\Options(
        path: '/messages/{id}',
        summary: 'Получить список доступных HTTP-методов для конкретного сообщения',
        tags: ['Messages'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Список доступных методов',
                headers: [
                    new OA\Header(header: 'Allow', description: 'Список разрешённых HTTP-методов', schema: new OA\Schema(type: 'string')),
                ]
            ),
        ]
    )]
    public function options(Request $request, Response $response, array $args = []): Response
    {
        $methods = isset($args['id'])
            ? 'GET, HEAD, PUT, PATCH, DELETE, OPTIONS'
            : 'GET, HEAD, POST, OPTIONS';

        return $response
            ->withStatus(200)
            ->withHeader('Allow', $methods);
    }

    #[OA\Head(
        path: '/messages',
        summary: 'Получить метаданные коллекции сообщений (без тела ответа)',
        tags: ['Messages'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Метаданные коллекции',
                headers: [
                    new OA\Header(header: 'X-Total-Count', description: 'Общее количество сообщений', schema: new OA\Schema(type: 'integer')),
                ]
            ),
        ]
    )]
    #[OA\Head(
        path: '/messages/{id}',
        summary: 'Получить метаданные конкретного сообщения (без тела ответа)',
        tags: ['Messages'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Метаданные сообщения',
                headers: [
                    new OA\Header(header: 'X-Resource-Id', description: 'ID сообщения', schema: new OA\Schema(type: 'integer')),
                ]
            ),
            new OA\Response(response: 404, description: 'Сообщение не найдено'),
        ]
    )]
    public function head(Request $request, Response $response, array $args = []): Response
    {
        if (isset($args['id'])) {
            $message = $this->messageService->find((int) $args['id']);

            if ($message === null) {
                return $this->error($response, 'Сообщение не найдено.', 404);
            }

            return $response
                ->withStatus(200)
                ->withHeader('Content-Type', 'application/json; charset=UTF-8')
                ->withHeader('X-Resource-Id', (string) $message['id']);
        }

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('X-Total-Count', (string) count($this->messageService->all()));
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    /** @param array<string, mixed> $payload @param array<string, string> $headers */
    private function json(Response $response, array $payload, int $status = 200, array $headers = []): Response
    {
        $response = $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8');

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response;
    }

    private function error(Response $response, string $message, int $status): Response
    {
        return $this->json($response, [
            'error' => [
                'message' => $message,
                'status' => $status,
            ],
        ], $status);
    }
}
