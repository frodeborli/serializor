<?php

declare(strict_types=1);

namespace Tests\Integration;

use Closure;
use ReflectionClass;
use RuntimeException;
use Serializor\FunctionDescription;
use Serializor\ReflectionClosure;
use Tests\Fixtures\TestCodeExtractor;

covers(ReflectionClosure::class);
covers(FunctionDescription::class);

beforeEach(
    fn() => (new ReflectionClass(ReflectionClosure::class))
        ->getProperty('functionCache')->setValue(null, [])
);

test('extracts the code from', function (): void {
    $expected = '%EXTRACTED CODE%';
    $reflectionClosure = new ReflectionClosure(fn() => null, new TestCodeExtractor($expected));

    $actual = $reflectionClosure->getCode();

    expect($actual)->toBe($expected);
});

test('indicates that `$this` was used', function (): void {
    $input = function (): object {
        return $this;
    };
    $reflectionClosure = new ReflectionClosure($input, new TestCodeExtractor('fn () => $this'));

    $actual = $reflectionClosure->usedThis();

    expect($actual)->toBeTrue();
});

test('indicates that `static` was used', function (): void {
    $input = function (): int {
        static $a = 5;
        return $a;
    };
    $reflectionClosure = new ReflectionClosure($input, new TestCodeExtractor('static fn () => null'));

    $actual = $reflectionClosure->usedStatic();

    expect($actual)->toBeTrue();
});

test('fails when trying to reflect upon a function created with `eval`', function (): void {
    /** @var Closure $input */
    eval('$input = static function (string $param): mixed {
        $param .= \'Text\';
        return $param;
    };');

    new ReflectionClosure($input, new TestCodeExtractor(''));
})
    ->throws(RuntimeException::class);

test('returns placeholder information for native functions', function (): void {
    $input = printf(...);
    $reflectionClosure = new ReflectionClosure($input, new TestCodeExtractor(''));

    expect($reflectionClosure->getCode())->toBe('/* native code */');
    expect($reflectionClosure->usedThis())->toBeFalse();
    expect($reflectionClosure->usedStatic())->toBeFalse();
});
