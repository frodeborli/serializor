<?php

declare(strict_types=1);

namespace Tests\Integration;

use Closure;
use Generator;
use ReflectionClass;
use RuntimeException;
use Serializor\FunctionDescription;
use Serializor\ReflectionClosure;

covers(ReflectionClosure::class);
covers(FunctionDescription::class);

beforeEach(
    fn() => (new ReflectionClass(ReflectionClosure::class))
        ->getProperty('functionCache')->setValue(null, [])
);

test('extracts the code from', function (Closure $input, string $expected): void {
    $reflectionClosure = new ReflectionClosure($input);

    $actual = $reflectionClosure->getCode();

    expect($actual)->toBe($expected);
})
    ->with(function (): Generator {
        $input = function (string $param): string {
            return $param;
        };
        yield 'a function' => [
            $input,
            'function (string $param): string {
            return $param;
        }',
        ];

        $input = function (float $param): array {
            return [(int) $param => [(string) $param], ((int) $param) + 1 => function () {}];
        };
        yield 'a function containing parentheses, brackets and braces' => [
            $input,
            'function (float $param): array {
            return [(int) $param => [(string) $param], ((int) $param) + 1 => function () {}];
        }',
        ];

        $input = fn(string $param): string => $param;
        yield 'an arrow function' => [
            $input,
            'fn(string $param): string => $param',
        ];

        $input = fn(string $param): string =>
        $param;
        yield 'a multi-line arrow function' => [
            $input,
            'fn(string $param): string =>
        $param',
        ];

        $input = static function (string $param): string {
            return $param;
        };
        yield 'a static function' => [
            $input,
            'static function (string $param): string {
            return $param;
        }',
        ];

        $input = static fn(string $param): string => $param;
        yield 'a static arrow function' => [
            $input,
            'static fn(string $param): string => $param',
        ];

        $input = static fn(string $param): string =>
        $param;
        yield 'a static multi-line arrow function' => [
            $input,
            'static fn(string $param): string =>
        $param',
        ];

        [function ($wrong1) {}, $input = function (string $param): string {
            return $param;
        }, function ($wrong2) {}];
        yield 'a function surrounded by other function declarations' => [
            $input,
            'function (string $param): string {
            return $param;
        }',
        ];

        [function ($wrong1) {}, $input = static function (string $param): string {
            return $param;
        }, function ($wrong2) {}];
        yield 'a static function surrounded by other function declarations' => [
            $input,
            'static function (string $param): string {
            return $param;
        }',
        ];

        [function ($wrong1) {}, $input = fn(string $param): string
        => $param, function ($wrong2) {}];
        yield 'an arrow function surrounded by other function declarations' => [
            $input,
            'fn(string $param): string
        => $param',
        ];

        [function ($wrong1) {}, $input = static fn(string $param): string
        => $param, function ($wrong2) {}];
        yield 'a static arrow function surrounded by other function declarations' => [
            $input,
            'static fn(string $param): string
        => $param',
        ];

        // TODO: A function surrounded by other function declarations on a single line
        // TODO: An arrow function surrounded by other function declarations on a single line
        // TODO: A static function surrounded by other function declarations on a single line
        // TODO: A static arrow function surrounded by other function declarations on a single line
        // TODO: More than one ignorable token between `T_STATIC` and `T_FUNCTION`
        // TODO: More than one ignorable token between `T_STATIC` and `T_FN`
        // TODO: `T_STATIC` on a other line than `T_FUNCTION`
        // TODO: `T_STATIC` on a other line than `T_FN`
    });

test('indicates that `$this` was used', function (): void {
    $input = function (): object {
        return $this;
    };
    $reflectionClosure = new ReflectionClosure($input);

    $actual = $reflectionClosure->usedThis();

    expect($actual)->toBeTrue();
});

test('indicates that `static` was used', function (): void {
    $input = function (): int {
        static $a = 5;
        return $a;
    };
    $reflectionClosure = new ReflectionClosure($input);

    $actual = $reflectionClosure->usedStatic();

    expect($actual)->toBeTrue();
});

test('fails when trying to reflect upon a function created with `eval`', function (): void {
    /** @var Closure $input */
    eval('$input = static function (string $param): mixed {
        $param .= \'Text\';
        return $param;
    };');

    new ReflectionClosure($input);
})
    ->throws(RuntimeException::class);

test('returns placeholder information for native functions', function (): void {
    $input = printf(...);
    $reflectionClosure = new ReflectionClosure($input);

    expect($reflectionClosure->getCode())->toBe('/* native code */');
    expect($reflectionClosure->usedThis())->toBeFalse();
    expect($reflectionClosure->usedStatic())->toBeFalse();
});
