<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

final class MessageService
{
    /** @var array<int, array<string, mixed>> */
    private array $messages = [];

    public function __construct(private readonly string $storagePath)
    {
        $directory = dirname($this->storagePath);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        if (!is_file($this->storagePath)) {
            file_put_contents($this->storagePath, "[]\n", LOCK_EX);
        }

        $decoded = json_decode((string) file_get_contents($this->storagePath), true, 512, JSON_THROW_ON_ERROR);

        $this->messages = array_map(
            static fn (array $message): array => [
                'id' => (int) $message['id'],
                'content' => (string) $message['content'],
                'author' => (string) ($message['author'] ?? 'anonymous'),
                'createdAt' => (string) $message['createdAt'],
                'updatedAt' => (string) $message['updatedAt'],
            ],
            is_array($decoded) ? $decoded : []
        );

        usort($this->messages, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->messages;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        foreach ($this->messages as $message) {
            if ($message['id'] === $id) {
                return $message;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $content = trim((string) ($data['content'] ?? ''));

        if ($content === '') {
            throw new InvalidArgumentException('Поле content обязательно и не может быть пустым.');
        }

        $author = trim((string) ($data['author'] ?? 'anonymous')) ?: 'anonymous';
        $now = gmdate(DATE_ATOM);
        $message = [
            'id' => $this->nextId(),
            'content' => $content,
            'author' => $author,
            'createdAt' => $now,
            'updatedAt' => $now,
        ];

        $this->messages[] = $message;
        $this->persist();

        return $message;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function replace(int $id, array $data): ?array
    {
        $message = $this->find($id);

        if ($message === null) {
            return null;
        }

        $content = trim((string) ($data['content'] ?? ''));

        if ($content === '') {
            throw new InvalidArgumentException('Поле content обязательно и не может быть пустым.');
        }

        $message['content'] = $content;
        $message['author'] = trim((string) ($data['author'] ?? 'anonymous')) ?: 'anonymous';
        $message['updatedAt'] = gmdate(DATE_ATOM);

        $this->updateInStorage($id, $message);
        $this->persist();

        return $message;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function update(int $id, array $data): ?array
    {
        $message = $this->find($id);

        if ($message === null) {
            return null;
        }

        if (array_key_exists('content', $data)) {
            $content = trim((string) $data['content']);

            if ($content === '') {
                throw new InvalidArgumentException('Поле content не может быть пустым.');
            }

            $message['content'] = $content;
        }

        if (array_key_exists('author', $data)) {
            $author = trim((string) $data['author']);
            $message['author'] = $author === '' ? 'anonymous' : $author;
        }

        $message['updatedAt'] = gmdate(DATE_ATOM);

        $this->updateInStorage($id, $message);
        $this->persist();

        return $message;
    }

    public function delete(int $id): bool
    {
        $before = count($this->messages);
        $this->messages = array_values(array_filter(
            $this->messages,
            static fn (array $message): bool => $message['id'] !== $id
        ));

        if ($before === count($this->messages)) {
            return false;
        }

        $this->persist();

        return true;
    }

    private function nextId(): int
    {
        $maxId = 0;

        foreach ($this->messages as $message) {
            $maxId = max($maxId, (int) $message['id']);
        }

        return $maxId + 1;
    }

    private function updateInStorage(int $id, array $message): void
    {
        foreach ($this->messages as $index => $existing) {
            if ($existing['id'] === $id) {
                $this->messages[$index] = $message;
                return;
            }
        }
    }

    private function persist(): void
    {
        file_put_contents(
            $this->storagePath,
            json_encode(array_values($this->messages), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
            LOCK_EX
        );
    }
}
