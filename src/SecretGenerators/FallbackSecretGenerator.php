<?php

declare(strict_types=1);

namespace Serializor\SecretGenerators;

use Throwable;

use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function hash;
use function random_bytes;

final class FallbackSecretGenerator implements SecretGenerator
{
    public function __construct(
        private string $pathToSecretFile,
    ) {}

    /** @throws SecretGenerationException If no suitable secret could be generated */
    public function generate(): string
    {
        if (!file_exists($this->pathToSecretFile)) {
            $hash = hash('sha256', random_bytes(32));

            file_put_contents($this->pathToSecretFile, $hash);
        }

        return file_get_contents($this->pathToSecretFile)
            ?: throw new SecretGenerationException('Could not retrieve fallback secret');
    }
}
