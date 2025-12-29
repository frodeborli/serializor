<?php

declare(strict_types=1);

namespace Tests;

use Serializor;
use Serializor\Codec;
use Serializor\SerializerError;

test('serialization with custom secret works', function (): void {
    $codec = new Codec('my-secret-key');

    $value = fn() => 'hello';
    $serialized = $codec->serialize($value);
    $result = $codec->unserialize($serialized);

    expect($result())->toBe('hello');
});

test('unsigned data rejected when secret is set', function (): void {
    // Serialize without secret
    $codecNoSecret = new Codec('');
    $value = 'test-value';
    $serialized = $codecNoSecret->serialize($value);

    // Try to unserialize with secret - should fail
    $codecWithSecret = new Codec('my-secret');

    expect(fn() => $codecWithSecret->unserialize($serialized))
        ->toThrow(SerializerError::class, 'Invalid signature');
});

test('signed data rejected without matching secret', function (): void {
    // Serialize with one secret
    $codec1 = new Codec('secret-1');
    $value = 'test-value';
    $serialized = $codec1->serialize($value);

    // Try to unserialize with different secret - should fail
    $codec2 = new Codec('secret-2');

    expect(fn() => $codec2->unserialize($serialized))
        ->toThrow(SerializerError::class, 'Invalid signature');
});

test('same secret can serialize and unserialize', function (): void {
    $secret = 'shared-secret-key';

    $codec1 = new Codec($secret);
    $codec2 = new Codec($secret);

    $value = ['data' => 'test', 'closure' => fn() => 42];
    $serialized = $codec1->serialize($value);
    $result = $codec2->unserialize($serialized);

    expect($result['data'])->toBe('test');
    expect($result['closure']())->toBe(42);
});

test('tampered data is rejected', function (): void {
    $codec = new Codec('my-secret');

    $value = 'original';
    $serialized = $codec->serialize($value);

    // Tamper with the data (modify a character in the payload)
    $parts = explode('|', $serialized, 2);
    $tampered = $parts[0] . '|' . strrev($parts[1]);

    expect(fn() => $codec->unserialize($tampered))
        ->toThrow(SerializerError::class, 'Invalid signature');
});

test('empty secret allows unsigned serialization', function (): void {
    $codec = new Codec('');

    $value = fn() => 'unsigned';
    $serialized = $codec->serialize($value);

    // Should not contain signature separator at the start
    expect(str_contains(substr($serialized, 0, 64), '|'))->toBeFalse();

    $result = $codec->unserialize($serialized);
    expect($result())->toBe('unsigned');
});

test('setDefaultSecret affects static methods', function (): void {
    // Set a custom secret
    Serializor::setDefaultSecret('test-secret');

    $value = fn() => 'with-secret';
    $serialized = Serializor::serialize($value);

    // Signature should be present (64 hex chars + |)
    expect(strlen($serialized))->toBeGreaterThan(65);
    expect($serialized[64])->toBe('|');

    $result = Serializor::unserialize($serialized);
    expect($result())->toBe('with-secret');

    // Reset to machine secret for other tests
    Serializor::setDefaultSecret(Serializor::getMachineSecret());
});

test('closure with secret preserves functionality', function (): void {
    $codec = new Codec('closure-secret');

    $multiplier = 3;
    $closure = fn(int $x) => $x * $multiplier;

    $serialized = $codec->serialize($closure);
    $result = $codec->unserialize($serialized);

    expect($result(7))->toBe(21);
});
