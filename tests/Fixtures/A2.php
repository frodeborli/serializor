<?php
namespace Tests\Fixtures;

class A2
{
    private $phrase = 'Hello, World!';

    private $closure1;

    private $closure2;

    private $closure3;

    public function __construct()
    {
        $this->closure1 = function () {
            return $this->phrase;
        };
        $this->closure2 = function () {
            return $this;
        };
        $this->closure3 = function () {
            $c = $this->closure2;

            return $this === $c();
        };
    }

    public function getPhrase()
    {
        $c = $this->closure1;

        return $c();
    }

    public function getEquality()
    {
        $c = $this->closure3;

        return $c();
    }
}
