<?php

declare(strict_types=1);

namespace Tests\Unit\SecretGenerators;

use Serializor\SecretGenerators\LinuxSecretGenerator;
use Serializor\SecretGenerators\SecretGenerationException;

test('generates a secret hash on linux machines', function (): void {
    $secretGenerator = new LinuxSecretGenerator();

    $actual = $secretGenerator->generate();

    expect($actual)->not()->toBeNull();
})->coversClass(LinuxSecretGenerator::class)->onlyOnLinux();

test('throws an exception if secret hash could not be generated', function (): void {
    $secretGenerator = new LinuxSecretGenerator();

    $secretGenerator->generate();
})->throws(SecretGenerationException::class)->skipOnLinux();
