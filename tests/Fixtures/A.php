<?php
namespace Tests\Fixtures;

class A
{
    private string $privateValue = 'private called';

    protected static function aStaticProtected()
    {
        return 'static protected called';
    }

    protected function aProtected()
    {
        return 'protected called';
    }

    public function aPublic()
    {
        return 'public called';
    }

    public function getPrivateClosure(): \Closure
    {
        return function (): string {
            return $this->privateValue;
        };
    }

    public function getProtectedClosure(): \Closure
    {
        return function (): string {
            return $this->aProtected();
        };
    }
}
