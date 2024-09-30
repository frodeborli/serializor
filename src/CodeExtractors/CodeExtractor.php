<?php

declare(strict_types=1);

namespace Serializor\CodeExtractors;

use Reflector;

interface CodeExtractor
{
    /** @param array<string, string> $memberNamesToDiscard */
    public function extract(Reflector $reflection, array $memberNamesToDiscard, string $code): string;
}
