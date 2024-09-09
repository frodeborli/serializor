<?php

declare(strict_types=1);

namespace Tests\Unit\SecretGenerators;

use Serializor\SecretGenerators\SecretGenerationException;
use Serializor\SecretGenerators\SolarisSecretGenerator;

test('generates a secret hash on solaris machines', function (): void {
    $secretGenerator = new SolarisSecretGenerator();

    $actual = $secretGenerator->generate();

    expect($actual)->not()->toBeNull();
})->coversClass(SolarisSecretGenerator::class)
    ->skip(fn(): bool => PHP_OS_FAMILY !== 'Solaris', 'This test is skipped on [' . PHP_OS_FAMILY . '].');

test('throws an exception if secret hash could not be generated', function (): void {
    $secretGenerator = new SolarisSecretGenerator();

    $secretGenerator->generate();
})
    ->throws(SecretGenerationException::class)
    ->coversClass(SolarisSecretGenerator::class)
    ->skip(fn(): bool => PHP_OS_FAMILY === 'Solaris', 'This test is skipped on [Solaris].');
