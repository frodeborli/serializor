<?php

declare(strict_types=1);

namespace Tests\Unit\SecretGenerators;

use Serializor\SecretGenerators\SecretGenerationException;
use Serializor\SecretGenerators\WindowsSecretGenerator;

use const PHP_OS_FAMILY;

covers(WindowsSecretGenerator::class);

test('generates a secret hash on windows machines', function (): void {
    $secretGenerator = new WindowsSecretGenerator();

    $actual = $secretGenerator->generate();

    expect($actual)->not()->toBeNull();
})
    ->skip(fn(): bool => PHP_OS_FAMILY !== 'Windows', 'This test is skipped on [' . PHP_OS_FAMILY . '].');

test('throws an exception if secret hash could not be generated', function (): void {
    $secretGenerator = new WindowsSecretGenerator();

    $secretGenerator->generate();
})
    ->throws(SecretGenerationException::class)
    ->skip(fn(): bool => PHP_OS_FAMILY === 'Windows', 'This test is skipped on [Windows].');
