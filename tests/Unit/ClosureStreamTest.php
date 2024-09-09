<?php

declare(strict_types=1);

namespace Tests\Unit;

use Serializor\ClosureStream;

use function in_array;
use function stream_get_wrappers;

test('can be registered as stream wrapper', function (): void {
    ClosureStream::register();

    $actual = in_array(ClosureStream::PROTOCOL, stream_get_wrappers());

    expect($actual)->toBe(true);
})->coversClass(ClosureStream::class);

test('executes arbitrary code when registered as stream wrapper', function (): void {
    ClosureStream::register();

    $actual = require ClosureStream::PROTOCOL . '://return 5';

    expect($actual)->toBe(5);
})->coversClass(ClosureStream::class);
