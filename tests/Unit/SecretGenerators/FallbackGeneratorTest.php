<?php

declare(strict_types=1);

namespace Tests\Unit\SecretGenerators;

use Serializor\SecretGenerators\FallbackSecretGenerator;
use Serializor\SecretGenerators\SecretGenerationException;

use function file_exists;
use function hash;
use function random_bytes;
use function restore_error_handler;
use function set_error_handler;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const E_WARNING;

test('generates a secret locally', function (): void {
    $path = createPathToFileThatDoesNotExist();
    $secretGenerator = new FallbackSecretGenerator($path);

    $actual = $secretGenerator->generate();

    expect($actual)->not()->toBeNull();
    expect($path)->toBeFile();
    unlink($path);
})
    ->coversClass(FallbackSecretGenerator::class);

test('throws an exception if secret hash could not be generated', function (): void {
    $secretGenerator = new FallbackSecretGenerator('.');
    set_error_handler(fn(): bool => true, E_WARNING);

    $secretGenerator->generate();

    restore_error_handler();
})
    ->throws(SecretGenerationException::class)
    ->coversClass(FallbackSecretGenerator::class);

function createPathToFileThatDoesNotExist(): string
{
    do {
        $path = __DIR__ . DIRECTORY_SEPARATOR . hash('sha256', random_bytes(32));
    } while (file_exists($path));

    return $path;
}
