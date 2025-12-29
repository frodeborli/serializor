<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Test fixture for __serialize/__unserialize with closures.
 */
class ObjectWithSerialize
{
    private string $name;
    private \Closure $callback;

    public function __construct(string $name, \Closure $callback)
    {
        $this->name = $name;
        $this->callback = $callback;
    }

    public function __serialize(): array
    {
        return [
            'name' => $this->name,
            'callback' => $this->callback,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->name = $data['name'];
        $this->callback = $data['callback'];
    }

    public function run(): mixed
    {
        return ($this->callback)($this->name);
    }
}
