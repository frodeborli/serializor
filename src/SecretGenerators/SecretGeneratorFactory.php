<?php

declare(strict_types=1);

namespace Serializor\SecretGenerators;

final class SecretGeneratorFactory
{
    public function __construct(
        private string $pathToSecretFile,
    ) {}

    public function create(string $platform): SecretGenerator
    {
        return match ($platform) {
            'Windows' => new WindowsSecretGenerator(),
            'Linux'   => new LinuxSecretGenerator(),
            'Darwin'  => new MacSecretGenerator(),
            'Solaris' => new SolarisSecretGenerator(),
            'BSD'     => new BsdSecretGenerator(),
            default   => new FallbackSecretGenerator($this->pathToSecretFile),
        };
    }
}
