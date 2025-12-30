<?php

declare(strict_types=1);

/**
 * Tests for PHP 8.2+ features.
 * These tests are skipped on older PHP versions.
 */

namespace Tests;

use Tests\Fixtures\Util;

// Conditionally load PHP 8.2 fixtures
if (PHP_VERSION_ID >= 80200) {
    require_once __DIR__ . '/Fixtures/PHP82/TraitWithConstant.php';
    require_once __DIR__ . '/Fixtures/PHP82/TraitConstantUser.php';
    require_once __DIR__ . '/Fixtures/PHP82/DynamicPropsClass.php';
    require_once __DIR__ . '/Fixtures/PHP82/DnfTypesClass.php';
    require_once __DIR__ . '/Fixtures/PHP82/StandaloneTypesClass.php';
}

// ============================================================================
// Readonly Classes (PHP 8.2)
// Note: ReadonlyClass uses readonly *property* (PHP 8.1), not readonly *class*
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
    $obj = new \Tests\Fixtures\PHP82\DnfTypesClass();
    $fn = $obj->getDnfParameterClosure();

    $result = Util::s($fn);

    $arrayIterator = new \ArrayIterator([1, 2, 3]);
    expect($result($arrayIterator))->toBe(3);

    $arrayObject = new \ArrayObject([1, 2, 3, 4]);
    expect($result($arrayObject))->toBe(4);
})->skip(PHP_VERSION_ID < 80200, 'Requires PHP 8.2+');

test('closure with DNF type in return', function (): void {
    $obj = new \Tests\Fixtures\PHP82\DnfTypesClass();
    $fn = $obj->getDnfReturnClosure();

    $result = Util::s($fn);

    $iter = $result(true);
    expect($iter)->toBeInstanceOf(\ArrayIterator::class);
    expect(count($iter))->toBe(2);
    expect($result(false))->toBeNull();
})->skip(PHP_VERSION_ID < 80200, 'Requires PHP 8.2+');

// ============================================================================
// null/false/true as standalone types (PHP 8.2)
// ============================================================================

test('closure with null standalone type', function (): void {
    $obj = new \Tests\Fixtures\PHP82\StandaloneTypesClass();
    $fn = $obj->getNullTypeClosure();

    $result = Util::s($fn);

    expect($result())->toBeNull();
})->skip(PHP_VERSION_ID < 80200, 'Requires PHP 8.2+');

test('closure with false standalone type', function (): void {
    $obj = new \Tests\Fixtures\PHP82\StandaloneTypesClass();
    $fn = $obj->getFalseTypeClosure();

    $result = Util::s($fn);

    expect($result())->toBeFalse();
})->skip(PHP_VERSION_ID < 80200, 'Requires PHP 8.2+');

test('closure with true standalone type', function (): void {
    $obj = new \Tests\Fixtures\PHP82\StandaloneTypesClass();
    $fn = $obj->getTrueTypeClosure();

    $result = Util::s($fn);

    expect($result())->toBeTrue();
})->skip(PHP_VERSION_ID < 80200, 'Requires PHP 8.2+');

// ============================================================================
// Constants in Traits (PHP 8.2)
// ============================================================================

test('closure using trait constant', function (): void {
    $obj = new \Tests\Fixtures\PHP82\TraitConstantUser();

    $fn = $obj->getClosure();

    $result = Util::s($fn);

    expect($result())->toBe('trait_value');
})->skip(PHP_VERSION_ID < 80200, 'Requires PHP 8.2+');

// ============================================================================
// AllowDynamicProperties attribute (PHP 8.2)
// ============================================================================

test('object with dynamic properties and closure', function (): void {
    $obj = new \Tests\Fixtures\PHP82\DynamicPropsClass();
    $obj->dynamicProp = 'dynamic_value';
    $obj->closure = fn() => $obj->dynamicProp;

    $result = Util::s($obj);

    expect($result->dynamicProp)->toBe('dynamic_value');
    expect(($result->closure)())->toBe('dynamic_value');
})->skip(PHP_VERSION_ID < 80200, 'Requires PHP 8.2+');
