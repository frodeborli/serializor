<?php

declare(strict_types=1);

/**
 * Tests for PHP 8.2+ features.
 * These tests verify serialization of PHP 8.2 language features.
 */

namespace Tests;

use Tests\Fixtures\Util;

// ============================================================================
// Readonly Classes (PHP 8.2)
// ============================================================================

test('readonly class with closure property', function (): void {
    $obj = new \Tests\Fixtures\ReadonlyClass();

    expect($obj->func)->toBeInstanceOf(\Closure::class);

    $result = Util::s($obj);

    expect($result->func)->toBeInstanceOf(\Closure::class);
    expect(($result->func)())->toBe($result); // The closure returns $this
});

// ============================================================================
// DNF Types (Disjunctive Normal Form) - PHP 8.2
// ============================================================================

test('closure with DNF type parameter', function (): void {
    // DNF type: (A&B)|C
    $fn = function ((\Countable&\Iterator)|\ArrayObject $value): int {
        return count($value);
    };

    $result = Util::s($fn);

    $arrayIterator = new \ArrayIterator([1, 2, 3]);
    expect($result($arrayIterator))->toBe(3);

    $arrayObject = new \ArrayObject([1, 2, 3, 4]);
    expect($result($arrayObject))->toBe(4);
});

test('closure with DNF type in return', function (): void {
    $fn = function (bool $flag): (\Iterator&\Countable)|null {
        return $flag ? new \ArrayIterator([1, 2]) : null;
    };

    $result = Util::s($fn);

    $iter = $result(true);
    expect($iter)->toBeInstanceOf(\ArrayIterator::class);
    expect(count($iter))->toBe(2);
    expect($result(false))->toBeNull();
});

// ============================================================================
// null/false/true as standalone types (PHP 8.2)
// ============================================================================

test('closure with null standalone type', function (): void {
    $fn = function (): null {
        return null;
    };

    $result = Util::s($fn);

    expect($result())->toBeNull();
});

test('closure with false standalone type', function (): void {
    $fn = function (): false {
        return false;
    };

    $result = Util::s($fn);

    expect($result())->toBeFalse();
});

test('closure with true standalone type', function (): void {
    $fn = function (): true {
        return true;
    };

    $result = Util::s($fn);

    expect($result())->toBeTrue();
});

// ============================================================================
// Constants in Traits (PHP 8.2)
// ============================================================================

test('closure using trait constant', function (): void {
    $obj = new class {
        use \Tests\Fixtures\TraitWithConstant;

        public function getClosure(): \Closure
        {
            return fn() => self::TRAIT_CONST;
        }
    };

    $fn = $obj->getClosure();

    $result = Util::s($fn);

    expect($result())->toBe('trait_value');
});

// ============================================================================
// AllowDynamicProperties attribute (PHP 8.2)
// ============================================================================

test('object with dynamic properties and closure', function (): void {
    $obj = new \Tests\Fixtures\DynamicPropsClass();
    $obj->dynamicProp = 'dynamic_value';
    $obj->closure = fn() => $obj->dynamicProp;

    $result = Util::s($obj);

    expect($result->dynamicProp)->toBe('dynamic_value');
    expect(($result->closure)())->toBe('dynamic_value');
});
