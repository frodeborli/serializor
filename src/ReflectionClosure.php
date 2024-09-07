<?php

namespace Serializor;

use ReflectionFunction;
use Serializor\Transformers\ClosureTransformer;

class ReflectionClosure extends ReflectionFunction
{

    public function getCode(): string
    {
        return ClosureTransformer::getCode($this, $usedThis, $usedStatic);
    }
}
