<?php

declare(strict_types=1);

namespace Tests\Unit\SecretGenerators;

use Serializor\SecretGenerators\BsdSecretGenerator;
use Serializor\SecretGenerators\SecretGenerationException;

covers(BsdSecretGenerator::class);

test('generates a secret hash on BSD machines', function (): void {
    $secretGenerator = new BsdSecretGenerator();

    $actual = $secretGenerator->generate();

    expect($actual)->not()->toBeNull();
})
    ->skip(fn(): bool => PHP_OS_FAMILY !== 'BSD', 'This test is skipped on [' . PHP_OS_FAMILY . '].');

test('throws an exception if secret hash could not be generated', function (): void {
    $secretGenerator = new BsdSecretGenerator();

    $secretGenerator->generate();
})
    ->throws(SecretGenerationException::class)
    ->skip(fn(): bool => PHP_OS_FAMILY === 'BSD', 'This test is skipped on [BSD].');
