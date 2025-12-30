<?php

declare(strict_types=1);

namespace Tests\Fixtures\PHP82;

/**
 * Class with closures using DNF (Disjunctive Normal Form) types (PHP 8.2 feature).
 */
class DnfTypesClass
{
    /**
     * Get a closure with DNF type parameter.
     */
    public function getDnfParameterClosure(): \Closure
    {
        return function ((\Countable&\Iterator)|\ArrayObject $value): int {
            return count($value);
        };
    }

    /**
     * Get a closure with DNF type in return.
     */
    public function getDnfReturnClosure(): \Closure
    {
        return function (bool $flag): (\Iterator&\Countable)|null {
            return $flag ? new \ArrayIterator([1, 2]) : null;
        };
    }
}
