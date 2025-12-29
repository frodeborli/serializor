<?php

declare(strict_types=1);

namespace Tests\Fixtures\PHP84;

/**
 * Test class demonstrating PHP 8.4 property hooks.
 */
class PropertyHooksClass
{
    private string $_name = '';

    public string $name {
        get => strtoupper($this->_name);
        set => $this->_name = $value;
    }

    public string $firstName = 'John';
    public string $lastName = 'Doe';

    public string $fullName {
        get => $this->firstName . ' ' . $this->lastName;
    }

    public int $value = 10;

    public int $doubled {
        get => $this->value * 2;
    }

    public \Closure $calculator;

    public function __construct(string $name = 'test')
    {
        $this->name = $name;
        $this->calculator = fn() => $this->doubled + 5;
    }
}
