<?php

declare(strict_types=1);

namespace Tests\Unit\SecretGenerators;

use Serializor\SecretGenerators\SecretGenerationException;
use Serializor\SecretGenerators\WindowsSecretGenerator;

test('generates a secret hash on windows machines', function (): void {
    $secretGenerator = new WindowsSecretGenerator();

    $actual = $secretGenerator->generate();

    expect($actual)->not()->toBeNull();
})->coversClass(WindowsSecretGenerator::class)->onlyOnWindows();

test('throws an exception if secret hash could not be generated', function (): void {
    $secretGenerator = new WindowsSecretGenerator();

    $secretGenerator->generate();
})->throws(SecretGenerationException::class)->skipOnWindows();
