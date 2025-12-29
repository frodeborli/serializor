<?php

declare(strict_types=1);

/**
 * Tests for PHP 8.3+ features.
 * These tests are skipped on older PHP versions.
 */

namespace Tests;

use Tests\Fixtures\Util;

beforeEach(function () {
    if (PHP_VERSION_ID < 80300) {
        $this->markTestSkipped('Requires PHP 8.3+');
    }
});

// ============================================================================
// Typed Class Constants (PHP 8.3)
// ============================================================================

test('closure using typed class constant', function (): void {
    $obj = new class {
        public const string NAME = 'TestName';
        public const int VALUE = 42;

        public function getClosure(): \Closure
        {
            return fn() => self::NAME . ':' . self::VALUE;
        }
    };

    $fn = $obj->getClosure();

    $result = Util::s($fn);

    expect($result())->toBe('TestName:42');
});

// ============================================================================
// #[\Override] Attribute (PHP 8.3)
// ============================================================================

test('closure from class with Override attribute', function (): void {
    // Test that closures from classes using #[\Override] work correctly
    $obj = new \Tests\Fixtures\PHP83\OverrideClass();

    $fn = $obj->getClosure();

    $result = Util::s($fn);

    expect($result())->toBe('child:value');
});

// ============================================================================
// Dynamic class constant fetch (PHP 8.3)
// ============================================================================

test('closure with dynamic class constant fetch', function (): void {
    $fn = function (string $constName): mixed {
        return \DateTimeInterface::{$constName};
    };

    $result = Util::s($fn);

    expect($result('ATOM'))->toBe(\DateTimeInterface::ATOM);
    expect($result('RFC3339'))->toBe(\DateTimeInterface::RFC3339);
});

// ============================================================================
// Deep cloning of readonly properties (PHP 8.3)
// ============================================================================

test('readonly object cloning in closure', function (): void {
    $obj = new readonly class('original') {
        public function __construct(
            public string $value
        ) {}

        public function getClosure(): \Closure
        {
            return function (): self {
                // PHP 8.3 allows this in __clone context
                return clone $this;
            };
        }
    };

    $fn = $obj->getClosure();

    $result = Util::s($fn);

    $cloned = $result();
    expect($cloned->value)->toBe('original');
});

// ============================================================================
// Anonymous readonly classes (PHP 8.3)
// ============================================================================

test('anonymous readonly class with closure', function (): void {
    $obj = new readonly class {
        public string $name;
        public \Closure $handler;

        public function __construct()
        {
            $this->name = 'ReadonlyAnon';
            $this->handler = fn() => $this->name;
        }
    };

    $result = Util::s($obj);

    expect($result->name)->toBe('ReadonlyAnon');
    expect(($result->handler)())->toBe('ReadonlyAnon');
});
