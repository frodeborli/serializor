<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Closure;

enum MyEnum: string
{
    case CASE1 = "c1";
    case CASE2 = "c2";

    public function getClosure(): Closure
    {
        return fn() => $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
