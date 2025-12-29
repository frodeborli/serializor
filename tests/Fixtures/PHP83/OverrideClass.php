<?php

declare(strict_types=1);

namespace Tests\Fixtures\PHP83;

/**
 * Base class for testing #[\Override] attribute.
 */
abstract class BaseClass
{
    protected string $value = 'value';

    abstract public function getValue(): string;
}

/**
 * Child class using #[\Override] attribute (PHP 8.3 feature).
 */
class OverrideClass extends BaseClass
{
    #[\Override]
    public function getValue(): string
    {
        return 'child:' . $this->value;
    }

    public function getClosure(): \Closure
    {
        return fn() => $this->getValue();
    }
}
