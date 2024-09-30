<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Reflector;
use Serializor\CodeExtractors\CodeExtractor;

final class TestCodeExtractor implements CodeExtractor
{
    public function __construct(
        private string $code,
    ) {}

    public function extract(Reflector $reflection, array $memberNamesToDiscard, string $code): string
    {
        return $this->code;
    }
}
