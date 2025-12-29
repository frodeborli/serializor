<?php

declare(strict_types=1);

namespace Serializor\Primitive;

use Closure;
use Serializor\Primitive;

/**
 * Lightweight serialization for named functions, static methods, and instance methods.
 *
 * Stores just the callable reference without the overhead of Stasis transformation.
 */
final class Callable_ extends Primitive
{
    /**
     * @param string|array $callable The callable: "funcName", ["Class", "method"], or [object, "method"]
     */
    public function __construct(
        public readonly string|array $callable,
    ) {}

    public function instantiate(): Closure
    {
        return Closure::fromCallable($this->callable);
    }
}
