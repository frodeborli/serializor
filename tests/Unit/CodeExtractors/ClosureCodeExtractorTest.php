<?php

declare(strict_types=1);

namespace Tests\Unit\CodeExtractors;

use Closure;
use Generator;
use ReflectionFunction;
use RuntimeException;
use Serializor\CodeExtractors\ClosureCodeExtractor;

use function file_get_contents;

covers(ClosureCodeExtractor::class);

test('extracts code', function (Closure $input, string $expected): void {
    $codeExtractor = new ClosureCodeExtractor();

    $reflectionClosure = new ReflectionFunction($input);
    $actual = $codeExtractor->extract($reflectionClosure, [], file_get_contents($reflectionClosure->getFileName()));

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
            return [(int) $param => [(string) $param], (int) $param + 1 => function () {}];
        };
        yield 'a function containing parentheses, brackets and braces' => [
            $input,
            'function (float $param): array {
    return [(int) $param => [(string) $param], (int) $param + 1 => function () {
    }];
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
            'fn(string $param): string => $param',
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
            'static fn(string $param): string => $param',
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
            'fn(string $param): string => $param',
        ];

        [function ($wrong1) {}, $input = static fn(string $param): string
        => $param, function ($wrong2) {}];
        yield 'a static arrow function surrounded by other function declarations' => [
            $input,
            'static fn(string $param): string => $param',
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

test('fails when no closure was found', function (): void {
    $reflectionFunction = new ReflectionFunction(fn() => null);
    $codeExtractor = new ClosureCodeExtractor();

    $codeExtractor->extract($reflectionFunction, [], '<?php $closure = "no closure here";');
})
    ->expectException(RuntimeException::class);

test('fails when more than one class was found', function (): void {
    $wrapper = [fn() => null, fn() => null];
    $reflectionFunction = new ReflectionFunction($wrapper[1]);
    $codeExtractor = new ClosureCodeExtractor();

    $codeExtractor->extract($reflectionFunction, [], file_get_contents($reflectionFunction->getFileName()));
})
    ->expectException(RuntimeException::class);
