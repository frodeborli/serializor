<?php

declare(strict_types=1);

/**
 * Tests for PHP 8.5+ features.
 * These tests are skipped on older PHP versions.
 */

namespace Tests;

use Tests\Fixtures\Util;

// Conditionally load PHP 8.5 fixtures
if (PHP_VERSION_ID >= 80500) {
    require_once __DIR__ . '/Fixtures/PHP85/PipeOperatorClass.php';
}

// ============================================================================
// Pipe Operator (PHP 8.5)
// ============================================================================

test('pipe operator: basic usage in closure', function (): void {
    $fn = \Tests\Fixtures\PHP85\PipeOperatorClass::getBasicPipeClosure();

    expect($fn('  hello  '))->toBe('HELLO');

    $result = Util::s($fn);

    expect($result('  world  '))->toBe('WORLD');
})->skip(PHP_VERSION_ID < 80500, 'Requires PHP 8.5+');

test('pipe operator: chained transformations', function (): void {
    $fn = \Tests\Fixtures\PHP85\PipeOperatorClass::getChainedPipeClosure();

    expect($fn('  HELLO WORLD  '))->toBe('Hello world');

    $result = Util::s($fn);

    expect($result('  PHP ROCKS  '))->toBe('Php rocks');
})->skip(PHP_VERSION_ID < 80500, 'Requires PHP 8.5+');

test('pipe operator: with custom functions', function (): void {
    $fn = \Tests\Fixtures\PHP85\PipeOperatorClass::getPipeWithCustomFunctions();

    expect($fn(5))->toBe(20); // (5 * 2) + 10

    $result = Util::s($fn);

    expect($result(5))->toBe(20);
    expect($result(10))->toBe(30); // (10 * 2) + 10
})->skip(PHP_VERSION_ID < 80500, 'Requires PHP 8.5+');

test('pipe operator: with array functions', function (): void {
    $fn = \Tests\Fixtures\PHP85\PipeOperatorClass::getPipeWithArrayFunctions();

    expect($fn([-1, 2, -3, 4, 5]))->toBe(22); // (2+4+5) * 2

    $result = Util::s($fn);

    expect($result([-1, 2, -3, 4, 5]))->toBe(22);
    expect($result([1, 2, 3]))->toBe(12); // (1+2+3) * 2
})->skip(PHP_VERSION_ID < 80500, 'Requires PHP 8.5+');

test('pipe operator: in object property closure', function (): void {
    $obj = new \Tests\Fixtures\PHP85\PipeOperatorClass();

    expect(($obj->transformer)('abc'))->toBe(['A', 'B', 'C']);

    $result = Util::s($obj);

    expect(($result->transformer)('xyz'))->toBe(['X', 'Y', 'Z']);
})->skip(PHP_VERSION_ID < 80500, 'Requires PHP 8.5+');
