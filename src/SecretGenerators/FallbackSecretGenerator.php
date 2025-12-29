<?php

declare(strict_types=1);

namespace Serializor\SecretGenerators;

use function file_get_contents;
use function file_put_contents;
use function hash;
use function is_dir;
use function is_file;
use function random_bytes;

final class FallbackSecretGenerator implements SecretGenerator
{
    public function __construct(
        private string $pathToSecretFile,
    ) {}

    /** @throws SecretGenerationException If no suitable secret could be generated */
    public function generate(): string
    {
        if (is_dir($this->pathToSecretFile)) {
            throw new SecretGenerationException('Could not retrieve fallback secret');
        }

        if (!is_file($this->pathToSecretFile)) {
            $hash = hash('sha256', random_bytes(32));

            file_put_contents($this->pathToSecretFile, $hash);
        }

        return file_get_contents($this->pathToSecretFile)
            ?: throw new SecretGenerationException('Could not retrieve fallback secret');
    }
}
