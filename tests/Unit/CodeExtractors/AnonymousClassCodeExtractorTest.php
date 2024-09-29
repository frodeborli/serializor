<?php

declare(strict_types=1);

namespace Tests\Unit\CodeExtractors;

use Generator;
use ReflectionObject;
use RuntimeException;
use Serializor\CodeExtractors\AnonymousClassCodeExtractor;
use Tests\Unit\CodeExtractors\BaseClass as AliasedClass;

use function file_get_contents;

covers(AnonymousClassCodeExtractor::class);

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
            class  extends \Tests\Unit\CodeExtractors\BaseClass
            {
            }
            EOF,
        ];

        yield 'a class with an interface' => [
            new class implements Interface1, Interface2 {},
            [],
            <<<EOF
            class  implements \Tests\Unit\CodeExtractors\Interface1, \Tests\Unit\CodeExtractors\Interface2
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
            new class(0, null) {
                public function __construct(
                    private int|(BaseClass&Interface1) $a,
                    private ?BaseClass $b,
                ) {}
            },
            ['__construct'],
            <<<EOF
            class 
            {
                private int|(\Tests\Unit\CodeExtractors\BaseClass&\Tests\Unit\CodeExtractors\Interface1) \$a;
                private ?\Tests\Unit\CodeExtractors\BaseClass \$b;
            }
            EOF,
        ];

        yield 'a class with not-qualified property type' => [
            new class {
                private BaseClass $a;
            },
            ['__construct'],
            <<<EOF
            class 
            {
                private \Tests\Unit\CodeExtractors\BaseClass \$a;
            }
            EOF,
        ];

        yield 'a class with aliased property type' => [
            new class {
                private AliasedClass $a;
            },
            ['__construct'],
            <<<EOF
            class 
            {
                private \Tests\Unit\CodeExtractors\BaseClass \$a;
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

/** @internal */
interface Interface2 {}
