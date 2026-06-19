#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Генератор openapi.json с поддержкой серверов из .env
 *
 * Использование: php bin/generate-openapi.php
 */

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

// Загружаем .env
$envFile = $root . '/.env';
if (is_file($envFile)) {
    Dotenv\Dotenv::createImmutable($root)->load();
}

use App\OpenApiSpec;
use OpenApi\Analysis;
use OpenApi\Generator;

$generator = new Generator();
$generator->withProcessorPipeline(static function ($pipeline): void {
    $pipeline->add(static function (Analysis $analysis): Analysis {
        $openApi = $analysis->openapi;
        if ($openApi !== null) {
            $servers = OpenApiSpec::getServers();
            if ($servers !== []) {
                $openApi->servers = $servers;
            }
        }

        return $analysis;
    });
});

$openapi = $generator->generate([$root . '/src', $root . '/public']);

if ($openapi !== null) {
    $outputPath = $root . '/public/openapi.json';
    file_put_contents($outputPath, $openapi->toJson());
    echo "OpenAPI spec generated: {$outputPath}\n";
} else {
    fwrite(STDERR, "Failed to generate OpenAPI spec\n");
    exit(1);
}
