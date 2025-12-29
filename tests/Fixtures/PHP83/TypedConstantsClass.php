<?php

declare(strict_types=1);

namespace Tests\Fixtures\PHP83;

/**
 * Class with typed constants (PHP 8.3 feature).
 */
class TypedConstantsClass
{
    public const string NAME = 'TestName';
    public const int VALUE = 42;

    public function getClosure(): \Closure
    {
        return fn() => self::NAME . ':' . self::VALUE;
    }
}
