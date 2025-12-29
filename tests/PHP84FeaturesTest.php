<?php

declare(strict_types=1);

/**
 * Tests for PHP 8.4+ features.
 * These tests are skipped on older PHP versions.
 */

namespace Tests;

use Tests\Fixtures\Util;

// Conditionally load PHP 8.4 fixtures
if (PHP_VERSION_ID >= 80400) {
    require_once __DIR__ . '/Fixtures/PHP84/PropertyHooksClass.php';
}

// ============================================================================
// Property Hooks (PHP 8.4)
// ============================================================================

test('property hooks: basic get hook', function (): void {
    $obj = new \Tests\Fixtures\PHP84\PropertyHooksClass('hello');

    expect($obj->name)->toBe('HELLO');

    $result = Util::s($obj);

    expect($result->name)->toBe('HELLO');
})->skip(PHP_VERSION_ID < 80400, 'Requires PHP 8.4+');

test('property hooks: computed fullName property', function (): void {
    $obj = new \Tests\Fixtures\PHP84\PropertyHooksClass();
    $obj->firstName = 'Jane';
    $obj->lastName = 'Smith';

    expect($obj->fullName)->toBe('Jane Smith');

    $result = Util::s($obj);

    expect($result->fullName)->toBe('Jane Smith');
    expect($result->firstName)->toBe('Jane');
    expect($result->lastName)->toBe('Smith');
})->skip(PHP_VERSION_ID < 80400, 'Requires PHP 8.4+');

test('property hooks: doubled computed property', function (): void {
    $obj = new \Tests\Fixtures\PHP84\PropertyHooksClass();
    $obj->value = 15;

    expect($obj->doubled)->toBe(30);

    $result = Util::s($obj);

    expect($result->value)->toBe(15);
    expect($result->doubled)->toBe(30);
})->skip(PHP_VERSION_ID < 80400, 'Requires PHP 8.4+');

test('property hooks: closure using hooked properties', function (): void {
    $obj = new \Tests\Fixtures\PHP84\PropertyHooksClass();
    $obj->value = 10;

    expect(($obj->calculator)())->toBe(25); // 10*2 + 5

    $result = Util::s($obj);

    expect($result->value)->toBe(10);
    expect($result->doubled)->toBe(20);
    expect(($result->calculator)())->toBe(25);
})->skip(PHP_VERSION_ID < 80400, 'Requires PHP 8.4+');

test('property hooks: modify value after serialization', function (): void {
    $obj = new \Tests\Fixtures\PHP84\PropertyHooksClass('original');

    $result = Util::s($obj);

    // The set hook should still work after unserialization
    $result->name = 'modified';
    expect($result->name)->toBe('MODIFIED');
})->skip(PHP_VERSION_ID < 80400, 'Requires PHP 8.4+');
