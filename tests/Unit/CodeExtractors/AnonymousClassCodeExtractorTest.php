<?php

declare(strict_types=1);

namespace Tests\Unit\CodeExtractors;

use Generator;
use ReflectionObject;
use RuntimeException;
use Serializor\CodeExtractors\AnonymousClassCodeExtractor;
use Serializor\CodeExtractors\AnonymousClassVisitor;

use function file_get_contents;

covers(AnonymousClassCodeExtractor::class);
covers(AnonymousClassVisitor::class);

test('returns the source code of the given anonymous class reflection', function (object $class, array $memberNamesToDiscard, string $expected): void {
    $reflectionObject = new ReflectionObject($class);
    $codeExtractor = new AnonymousClassCodeExtractor();

    $actual = $codeExtractor->extract($reflectionObject, $memberNamesToDiscard, file_get_contents($reflectionObject->getFileName()));

    expect($actual)->toBe($expected);
})
    ->with(function (): Generator {
        yield 'the simplest case' => [
            new class {},
            [],
            <<<EOF
            class 
            {
            }
            EOF,
        ];

        yield 'the simplest case with initializer parentheses' => [
            new class() {},
            [],
            <<<EOF
            class 
            {
            }
            EOF,
        ];

        yield 'the simplest case with initializer arguments' => [
            new class(12, 34, '56') {},
            [],
            <<<EOF
            class 
            {
            }
            EOF,
        ];

        yield 'an extended class' => [
            new class extends BaseClass {},
            [],
            <<<EOF
            class  extends BaseClass
            {
            }
            EOF,
        ];

        yield 'a class with an interface' => [
            new class implements Interface1 {},
            [],
            <<<EOF
            class  implements Interface1
            {
            }
            EOF,
        ];

        yield 'a class with a method' => [
            new class {
                public function method(): void {}
            },
            [],
            <<<EOF
            class 
            {
                public function method(): void
                {
                }
            }
            EOF,
        ];

        yield 'a class with a method to be discarded' => [
            new class {
                public function method(): void {}
            },
            ['method'],
            <<<EOF
            class 
            {
            }
            EOF,
        ];

        yield 'a class with a constructor' => [
            new class {
                public function __construct() {}
            },
            [],
            <<<EOF
            class 
            {
                public function __construct()
                {
                }
            }
            EOF,
        ];

        yield 'a class with a constructor to be discarded' => [
            new class {
                public function __construct() {}
            },
            ['__construct'],
            <<<EOF
            class 
            {
            }
            EOF,
        ];

        yield 'a class with a constructor with promoted arguments' => [
            new class(0, '') {
                public function __construct(
                    private int $a,
                    private string $b,
                ) {}
            },
            ['__construct'],
            <<<EOF
            class 
            {
                private int \$a;
                private string \$b;
            }
            EOF,
        ];
    });

test('fails when no class was found', function (): void {
    $class = new class {};
    $reflectionObject = new ReflectionObject($class);
    $codeExtractor = new AnonymousClassCodeExtractor();

    $codeExtractor->extract($reflectionObject, [], '<?php $class = "no anonymous class here";');
})
    ->expectException(RuntimeException::class);

test('fails when more than one class was found', function (): void {
    $wrapper = [new class {}, new class {}];
    $reflectionObject = new ReflectionObject($wrapper[1]);
    $codeExtractor = new AnonymousClassCodeExtractor();

    $codeExtractor->extract($reflectionObject, [], file_get_contents($reflectionObject->getFileName()));
})
    ->expectException(RuntimeException::class);

/** @internal */
class BaseClass {}

/** @internal */
interface Interface1 {}
