<?php

declare(strict_types=1);

namespace Serializor;

use ReflectionFunction;
use Serializor\Transformers\ClosureTransformer;

final class ReflectionClosure extends ReflectionFunction
{
    public function getCode(): string
    {
        return ClosureTransformer::getCode($this, $usedThis, $usedStatic);
    }
}
