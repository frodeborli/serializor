<?php

declare(strict_types=1);

namespace Serializor\SecretGenerators;

interface SecretGenerator
{
    /** @throws SecretGenerationException If no suitable secret could be generated */
    public function generate(): string;
}
