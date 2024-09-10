<?php

declare(strict_types=1);

namespace Tests;

use Serializor;
use Serializor\Codec;

use function get_debug_type;
use function sprintf;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/


// uses(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
expect()->extend('toBeCode', function ($expected) {
    $code = (new ReflectionClosure($this->value))->getCode();

    expect($code)->toBe($expected);

    return $this->value;
});
*/

expect()->extend('toEqualAfterSerializeAndUnserialize', function (): static {
    $codec = new Codec('');

    $serialized = $codec->serialize($this->value);
    $actual     = $codec->unserialize($serialized);

    expect($serialized)->toBeString(
        sprintf(
            'Expected %s to be serialized into a string.',
            get_debug_type($this->value),
        ),
    );
    expect($this->value)->toEqual(
        $actual,
        sprintf(
            'Expected %s to be serialized and then unserialized into a value equal to %s.',
            get_debug_type($actual),
            get_debug_type($this->value),
        ),
    );

    return $this;
});

/**
 * Serializes and then unserializes a value, preserving its type.
 *
 * @template T
 * @param T $v The value to be serialized and unserialized
 * @return T The unserialized value, preserving the original type
 */
function s(mixed $v): mixed
{
    return Serializor::unserialize(Serializor::serialize($v));
}
