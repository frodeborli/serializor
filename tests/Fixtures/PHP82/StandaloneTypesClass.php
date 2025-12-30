<?php

declare(strict_types=1);

namespace Tests\Fixtures\PHP82;

/**
 * Class with closures using standalone null/false/true types (PHP 8.2 feature).
 */
class StandaloneTypesClass
{
    /**
     * Get a closure with null standalone return type.
     */
    public function getNullTypeClosure(): \Closure
    {
        return function (): null {
            return null;
        };
    }

    /**
     * Get a closure with false standalone return type.
     */
    public function getFalseTypeClosure(): \Closure
    {
        return function (): false {
            return false;
        };
    }

    /**
     * Get a closure with true standalone return type.
     */
    public function getTrueTypeClosure(): \Closure
    {
        return function (): true {
            return true;
        };
    }
}
