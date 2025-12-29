<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Helper for testing namespaced function serialization (opis/closure issue #78).
 */

// Define a namespaced function
function namespaced_helper(string $value): string
{
    return 'namespaced: ' . $value;
}

class NamespacedFunctions
{
    /**
     * Returns a closure that calls a function from the same namespace
     * without fully qualifying it.
     */
    public static function getClosureCallingNamespacedFunction(): \Closure
    {
        return function (string $value): string {
            // This calls Tests\Fixtures\namespaced_helper()
            return namespaced_helper($value);
        };
    }

    /**
     * Returns a closure that uses a function import.
     */
    public static function getClosureWithUseFunctionImport(): \Closure
    {
        // The use statement is at closure level via the namespace context
        return function (string $value): string {
            return \Tests\Fixtures\namespaced_helper($value);
        };
    }
}
