<?php
namespace Tests\Fixtures;

use Closure;

class ObjTypedUninit
{
    public Closure $value;
    public readonly Closure $c;
    public function __construct()
    {
        $this->c = function () {};
    }
}
