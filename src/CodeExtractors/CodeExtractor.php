<?php

declare(strict_types=1);

namespace Serializor\CodeExtractors;

use ReflectionObject;

interface CodeExtractor
{
    /** @param array<string, string> $memberNamesToDiscard */
    public function extract(ReflectionObject $reflection, array $memberNamesToDiscard, string $code): string;
}
