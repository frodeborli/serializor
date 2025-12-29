<?php

declare(strict_types=1);

/**
 * Tests for Laravel and Opis closure compatibility.
 * These tests verify that Serializor can unserialize closures
 * that were serialized by Laravel or Opis libraries.
 */

namespace Tests;

use Serializor\Serializor;

// ============================================================================
// Laravel SerializableClosure Compatibility
// ============================================================================

test('can unserialize Laravel SerializableClosure if library is installed', function (): void {
    if (!class_exists('Laravel\\SerializableClosure\\SerializableClosure')) {
        $this->markTestSkipped('Laravel SerializableClosure not installed');
    }

    $original = fn(int $x) => $x * 2;
    $wrapped = new \Laravel\SerializableClosure\SerializableClosure($original);
    $serialized = serialize($wrapped);

    // Serializor should unwrap the Laravel wrapper and return a native Closure
    $result = Serializor::unserialize($serialized);

    expect($result)->toBeInstanceOf(\Closure::class);
    expect($result(21))->toBe(42);
});

test('can unserialize Laravel closure with use variables', function (): void {
    if (!class_exists('Laravel\\SerializableClosure\\SerializableClosure')) {
        $this->markTestSkipped('Laravel SerializableClosure not installed');
    }

    $multiplier = 3;
    $original = fn(int $x) => $x * $multiplier;
    $wrapped = new \Laravel\SerializableClosure\SerializableClosure($original);
    $serialized = serialize($wrapped);

    $result = Serializor::unserialize($serialized);

    expect($result)->toBeInstanceOf(\Closure::class);
    expect($result(7))->toBe(21);
});

test('can unserialize array containing Laravel closures', function (): void {
    if (!class_exists('Laravel\\SerializableClosure\\SerializableClosure')) {
        $this->markTestSkipped('Laravel SerializableClosure not installed');
    }

    $data = [
        'add' => new \Laravel\SerializableClosure\SerializableClosure(fn($a, $b) => $a + $b),
        'multiply' => new \Laravel\SerializableClosure\SerializableClosure(fn($a, $b) => $a * $b),
    ];
    $serialized = serialize($data);

    $result = Serializor::unserialize($serialized);

    expect($result['add'])->toBeInstanceOf(\Closure::class);
    expect($result['multiply'])->toBeInstanceOf(\Closure::class);
    expect($result['add'](2, 3))->toBe(5);
    expect($result['multiply'](2, 3))->toBe(6);
});

test('can unserialize object with Laravel closure property', function (): void {
    if (!class_exists('Laravel\\SerializableClosure\\SerializableClosure')) {
        $this->markTestSkipped('Laravel SerializableClosure not installed');
    }

    $obj = new \stdClass();
    $obj->handler = new \Laravel\SerializableClosure\SerializableClosure(fn($x) => $x + 1);
    $obj->name = 'test';
    $serialized = serialize($obj);

    $result = Serializor::unserialize($serialized);

    expect($result->name)->toBe('test');
    expect($result->handler)->toBeInstanceOf(\Closure::class);
    expect(($result->handler)(5))->toBe(6);
});

// ============================================================================
// Opis Closure v4 Compatibility
// ============================================================================

test('can unserialize Opis v4 closure if library is installed', function (): void {
    if (!class_exists('Opis\\Closure\\Serializer')) {
        $this->markTestSkipped('Opis Closure v4 not installed');
    }

    $original = fn(int $x) => $x * 2;
    $serialized = \Opis\Closure\Serializer::serialize($original);

    // Serializor should detect opis format and use their deserializer
    $result = Serializor::unserialize($serialized);

    expect($result)->toBeInstanceOf(\Closure::class);
    expect($result(21))->toBe(42);
});

test('can unserialize Opis v4 closure with use variables', function (): void {
    if (!class_exists('Opis\\Closure\\Serializer')) {
        $this->markTestSkipped('Opis Closure v4 not installed');
    }

    $multiplier = 3;
    $original = fn(int $x) => $x * $multiplier;
    $serialized = \Opis\Closure\Serializer::serialize($original);

    $result = Serializor::unserialize($serialized);

    expect($result)->toBeInstanceOf(\Closure::class);
    expect($result(7))->toBe(21);
});

test('can unserialize Opis v4 array containing closures', function (): void {
    if (!class_exists('Opis\\Closure\\Serializer')) {
        $this->markTestSkipped('Opis Closure v4 not installed');
    }

    $data = [
        'add' => fn($a, $b) => $a + $b,
        'multiply' => fn($a, $b) => $a * $b,
    ];
    $serialized = \Opis\Closure\Serializer::serialize($data);

    $result = Serializor::unserialize($serialized);

    expect($result['add'])->toBeInstanceOf(\Closure::class);
    expect($result['multiply'])->toBeInstanceOf(\Closure::class);
    expect($result['add'](2, 3))->toBe(5);
    expect($result['multiply'](2, 3))->toBe(6);
});

test('can unserialize Opis v4 object with closure property', function (): void {
    if (!class_exists('Opis\\Closure\\Serializer')) {
        $this->markTestSkipped('Opis Closure v4 not installed');
    }

    $obj = new \stdClass();
    $obj->handler = fn($x) => $x + 1;
    $obj->name = 'test';
    $serialized = \Opis\Closure\Serializer::serialize($obj);

    $result = Serializor::unserialize($serialized);

    expect($result->name)->toBe('test');
    expect($result->handler)->toBeInstanceOf(\Closure::class);
    expect(($result->handler)(5))->toBe(6);
});
