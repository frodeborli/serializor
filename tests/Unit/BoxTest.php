<?php

declare(strict_types=1);

namespace Tests\Unit;

use Serializor\Box;
use TypeError;

covers(Box::class);

test('can be instantiated with correct properties', function (): void {
    $value = 'John Doe';
    $shortcuts = [];

    $box = new Box($value, $shortcuts);

    expect($box->value)->toBe($value);
    expect($box->shortcuts)->toBe($shortcuts);
});

test('throws an error when invalid types are passed', function (): void {
    new Box(123, 'invalid');
})->throws(TypeError::class);
