<?php

declare(strict_types=1);

namespace Tests\Transformers;

use Serializor\Transformers\ClosureTransformer;
use Tests\Fixtures\Closure;

use function Tests\s;

test('`\Closure` returning a class instance called `Closure`', function (): void {
    $expected = static fn(): Closure => new Closure();

    $actual = s($expected);

    expect($actual)->toEqual($expected);
})->coversClass(ClosureTransformer::class);
