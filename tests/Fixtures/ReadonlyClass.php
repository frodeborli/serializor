<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Closure;

class ReadonlyClass
{
    public readonly Closure $func;

    public function __construct()
    {
        $this->func = fn() => $this;
    }
}
