<?php

declare(strict_types=1);

namespace Tests\Transformers;

use Tests\Fixtures\Closure;
use Tests\Fixtures\Util;

use function Tests\s;

test('`\Closure` returning a class instance called `Closure`', function (): void {
    $expected = static fn(): Closure => new Closure();

    $actual = Util::s($expected);

    expect($actual)->toEqual($expected);
});
