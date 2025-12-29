<?php

declare(strict_types=1);

/**
 * Tests for PHP 8.3+ features.
 * These tests are skipped on older PHP versions.
 */

namespace Tests;

use Tests\Fixtures\Util;

// Conditionally load PHP 8.3 fixtures
if (PHP_VERSION_ID >= 80300) {
    require_once __DIR__ . '/Fixtures/PHP83/OverrideClass.php';
    require_once __DIR__ . '/Fixtures/PHP83/TypedConstantsClass.php';
    require_once __DIR__ . '/Fixtures/PHP83/DynamicConstantClass.php';
}

// ============================================================================
// Typed Class Constants (PHP 8.3)
// ============================================================================

test('closure using typed class constant', function (): void {
    $obj = new \Tests\Fixtures\PHP83\TypedConstantsClass();

    $fn = $obj->getClosure();

    $result = Util::s($fn);

    expect($result())->toBe('TestName:42');
})->skip(PHP_VERSION_ID < 80300, 'Requires PHP 8.3+');

// ============================================================================
// #[\Override] Attribute (PHP 8.3)
// ============================================================================

test('closure from class with Override attribute', function (): void {
    $obj = new \Tests\Fixtures\PHP83\OverrideClass();

    $fn = $obj->getClosure();

    $result = Util::s($fn);

    expect($result())->toBe('child:value');
})->skip(PHP_VERSION_ID < 80300, 'Requires PHP 8.3+');

// ============================================================================
// Dynamic class constant fetch (PHP 8.3)
// ============================================================================

test('closure with dynamic class constant fetch', function (): void {
    $obj = new \Tests\Fixtures\PHP83\DynamicConstantClass();

    $fn = $obj->getClosure();

    $result = Util::s($fn);

    expect($result('ATOM'))->toBe(\DateTimeInterface::ATOM);
    expect($result('RFC3339'))->toBe(\DateTimeInterface::RFC3339);
})->skip(PHP_VERSION_ID < 80300, 'Requires PHP 8.3+');
