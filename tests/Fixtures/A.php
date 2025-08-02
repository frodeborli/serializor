<?php
namespace Tests\Fixtures;

class A
{
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
}
