<?php

declare(strict_types=1);

namespace Serializor;

use Closure;
use ReflectionFunction;

/**
 * Stasis for named functions and static methods.
 * Very compact - just stores the callable reference.
 */
final class CallableStasis extends Stasis
{
    /**
     * The callable: "funcName" or ["ClassName", "methodName"]
     */
    private string|array $callable;

    public function __construct(string|array $callable)
    {
        $this->callable = $callable;
    }

    public function __serialize(): array
    {
        return [$this->callable];
    }

    public function __unserialize(array $data): void
    {
        $this->callable = $data[0];
    }

    public function getClassName(): string
    {
        return Closure::class;
    }

    public static function fromClosure(Closure $value, ReflectionFunction $rf): CallableStasis
    {
        $name = $rf->getName();
        $closureCalledClass = $rf->getClosureCalledClass();

        if ($closureCalledClass !== null) {
            // Static method
            return new CallableStasis([$closureCalledClass->getName(), $name]);
        }

        // Named function
        return new CallableStasis($name);
    }

    public function &getInstance(): mixed
    {
        if ($this->hasInstance()) {
            return $this->getCachedInstance();
        }

        $result = Closure::fromCallable($this->callable);
        $this->setInstance($result);
        return $result;
    }
}
