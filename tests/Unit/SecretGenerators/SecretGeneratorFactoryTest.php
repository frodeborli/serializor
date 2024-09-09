<?php

declare(strict_types=1);

namespace Tests\Unit\SecretGenerators;

use Serializor\SecretGenerators\BsdSecretGenerator;
use Serializor\SecretGenerators\FallbackSecretGenerator;
use Serializor\SecretGenerators\LinuxSecretGenerator;
use Serializor\SecretGenerators\MacSecretGenerator;
use Serializor\SecretGenerators\SecretGeneratorFactory;
use Serializor\SecretGenerators\SolarisSecretGenerator;
use Serializor\SecretGenerators\WindowsSecretGenerator;

covers(SecretGeneratorFactory::class);

test('creates a fitting secret generator for every platform', function (string $platform, string $expected): void {
    $factory = new SecretGeneratorFactory('');

    $actual = $factory->create($platform);

    expect($actual)->toBeInstanceOf($expected);
})
    ->with([
        'Windows' => ['Windows', WindowsSecretGenerator::class],
        'Linux'   => ['Linux', LinuxSecretGenerator::class],
        'Darwin'  => ['Darwin', MacSecretGenerator::class],
        'Solaris' => ['Solaris', SolarisSecretGenerator::class],
        'BSD'     => ['BSD', BsdSecretGenerator::class],
        'unknown' => ['unknown', FallbackSecretGenerator::class],
        '321364>' => ['321364>', FallbackSecretGenerator::class],
    ]);
