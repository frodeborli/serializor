<?php

declare(strict_types=1);

namespace Tests\Unit\SecretGenerators;

use Serializor\SecretGenerators\LinuxSecretGenerator;
use Serializor\SecretGenerators\SecretGenerationException;

covers(LinuxSecretGenerator::class);

test('generates a secret hash on linux machines', function (): void {
    $secretGenerator = new LinuxSecretGenerator();

    $actual = $secretGenerator->generate();

    expect($actual)->not()->toBeNull();
})
    ->skip(fn(): bool => PHP_OS_FAMILY !== 'Linux', 'This test is skipped on [' . PHP_OS_FAMILY . '].');

test('throws an exception if secret hash could not be generated', function (): void {
    $secretGenerator = new LinuxSecretGenerator();

    $secretGenerator->generate();
})
    ->throws(SecretGenerationException::class)
    ->skip(fn(): bool => PHP_OS_FAMILY === 'Linux', 'This test is skipped on [Linux].');
