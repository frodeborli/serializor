<?php

declare(strict_types=1);

namespace Tests\Unit\SecretGenerators;

use Serializor\SecretGenerators\MacSecretGenerator;
use Serializor\SecretGenerators\SecretGenerationException;

covers(MacSecretGenerator::class);

test('generates a secret hash on mac machines', function (): void {
    $secretGenerator = new MacSecretGenerator();

    $actual = $secretGenerator->generate();

    expect($actual)->not()->toBeNull();
})
    ->skip(fn(): bool => PHP_OS_FAMILY !== 'Darwin', 'This test is skipped on [' . PHP_OS_FAMILY . '].');

test('throws an exception if secret hash could not be generated', function (): void {
    $secretGenerator = new MacSecretGenerator();

    $secretGenerator->generate();
})
    ->throws(SecretGenerationException::class)
    ->skip(fn(): bool => PHP_OS_FAMILY === 'Darwin', 'This test is skipped on [Mac].');
