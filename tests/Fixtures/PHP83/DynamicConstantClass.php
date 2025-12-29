<?php

declare(strict_types=1);

namespace Tests\Fixtures\PHP83;

/**
 * Class demonstrating dynamic class constant fetch (PHP 8.3 feature).
 */
class DynamicConstantClass
{
    public function getConstant(string $constName): mixed
    {
        return \DateTimeInterface::{$constName};
    }

    public function getClosure(): \Closure
    {
        return function (string $constName): mixed {
            return \DateTimeInterface::{$constName};
        };
    }
}
