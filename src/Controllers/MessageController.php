<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\MessageService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MessageController
{
    public function __construct(private readonly MessageService $messageService)
    {
    }

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

    public function show(Request $request, Response $response, array $args): Response
    {
        $message = $this->messageService->find((int) $args['id']);

        if ($message === null) {
            return $this->error($response, 'Сообщение не найдено.', 404);
        }

        return $this->json($response, ['data' => $message], 200, ['X-Resource-Id' => (string) $message['id']]);
    }

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

    public function destroy(Request $request, Response $response, array $args): Response
    {
        if (!$this->messageService->delete((int) $args['id'])) {
            return $this->error($response, 'Сообщение не найдено.', 404);
        }

        return $response->withStatus(204);
    }

    public function options(Request $request, Response $response, array $args = []): Response
    {
        $methods = isset($args['id'])
            ? 'GET, HEAD, PUT, PATCH, DELETE, OPTIONS'
            : 'GET, HEAD, POST, OPTIONS';

        return $response
            ->withStatus(200)
            ->withHeader('Allow', $methods);
    }

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
