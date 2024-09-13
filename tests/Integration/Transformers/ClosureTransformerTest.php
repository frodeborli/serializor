<?php

declare(strict_types=1);

namespace Tests\Integration\Transformers;

use Serializor\Transformers\ClosureTransformer;

covers(ClosureTransformer::class);

test('`\Closure` returning a class instance called `Closure`', function (): void {
    $expected = fn(): \Tests\Fixtures\Closure => new \Tests\Fixtures\Closure();

    expect($expected)->toEqualAfterSerializeAndUnserialize();
});
