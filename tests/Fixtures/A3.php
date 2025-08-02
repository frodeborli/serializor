<?php
namespace Tests\Fixtures;

class A3
{
    private $closure;

    public function __construct($closure)
    {
        $this->closure = $closure;
    }

    public function hello()
    {
        return ($this->closure)();
    }
}
