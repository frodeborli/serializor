<?php

declare(strict_types=1);

namespace Tests\Unit\SecretGenerators;

use Serializor\SecretGenerators\MacSecretGenerator;
use Serializor\SecretGenerators\SecretGenerationException;

test('generates a secret hash on mac machines', function (): void {
    $secretGenerator = new MacSecretGenerator();

    $actual = $secretGenerator->generate();

    expect($actual)->not()->toBeNull();
})->coversClass(MacSecretGenerator::class)->onlyOnMac();

test('throws an exception if secret hash could not be generated', function (): void {
    $secretGenerator = new MacSecretGenerator();

    $secretGenerator->generate();
})
    ->throws(SecretGenerationException::class)->skipOnMac()
    ->coversClass(MacSecretGenerator::class)->onlyOnMac();
