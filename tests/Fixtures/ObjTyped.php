<?php
namespace Tests\Fixtures;

use Closure;

class ObjTyped
{
    public function __construct(
        public readonly Closure $closure,
        public readonly ?ObjTyped $objTyped
    ) {}
}
