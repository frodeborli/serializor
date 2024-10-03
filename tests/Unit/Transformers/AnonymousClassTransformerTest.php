<?php

declare(strict_types=1);

namespace Tests\Unit\Transformers;

use Generator;
use Reflector;
use Serializor\CodeExtractors\CodeExtractor;
use Serializor\SerializerError;
use Serializor\Stasis;
use Serializor\Transformers\AnonymousClassTransformer;
use Stringable;

covers(AnonymousClassTransformer::class);

describe('transforming', function (): void {
    $transformer = new AnonymousClassTransformer(new class implements CodeExtractor {
        public function extract(Reflector $reflection, array $memberNamesToDiscard, string $code): string
        {
            return '%EXPECTED EXTRACTED CODE%';
        }
    });

    test('informs about the value it transforms', function (mixed $input, bool $expected) use ($transformer): void {
        $actual = $transformer->transforms($input);

        expect($actual)->toBe($expected);
    })
        ->with([
            'string'  => ['1', false],
            'int'     => [2, false],
            'array'   => [[3], false],
            'object'  => [(object) [4], false],
            'closure' => [fn() => 5, false],
            'anonymous class' => [new class {}, true],
        ]);

    test('fails with invalid input', function (mixed $input) use ($transformer): void {
        $transformer->transform($input);
    })
        ->throws(SerializerError::class)
        ->with([
            'non-stasis value' => '5',
            'stasis with wrong class name' => (object)['a' => 'test'],
        ]);

    $anonymousClass = createAnonymousClass();

    describe('stores information about anonymous class in `Stasis`', function () use ($transformer, $anonymousClass): void {
        test('with an unique hash', function (object $otherAnonymousClass, bool $expected) use ($transformer, $anonymousClass): void {
            $actual1 = $transformer->transform($anonymousClass)->p['|hash'];
            $actual2 = $transformer->transform($otherAnonymousClass)->p['|hash'];

            expect($actual1 === $actual2)->toBe($expected);
        })
            ->with(function (): Generator {
                yield 'that is same with another object of the same anonymous class' => [
                    createAnonymousClass(),
                    true,
                ];
                yield 'that is different with another object of an anonymous class with the same code' => [
                    createOtherAnonymousClass(),
                    false,
                ];
            });

        test('with its code', function () use ($transformer, $anonymousClass): void {
            $actual = $transformer->transform($anonymousClass)->p['|code'];

            expect($actual)->toBe('%EXPECTED EXTRACTED CODE%');
        });

        test('with its parent class', function () use ($transformer, $anonymousClass): void {
            $expected = BaseClass::class;

            $actual = $transformer->transform($anonymousClass)->p['|extends'];

            expect($actual)->toBe($expected);
        });

        test('with its interfaces', function (object $anonymousClass, array $expected) use ($transformer): void {
            $actual = $transformer->transform($anonymousClass)->p['|implements'];

            expect($actual)->toBe($expected);
        })
            ->with(function (): Generator {
                yield 'single interface' => [
                    new class implements Interface1 {},
                    [Interface1::class],
                ];

                yield 'multiple interfaces' => [
                    new class implements Interface1, Interface2 {},
                    [Interface1::class, Interface2::class],
                ];
            });

        test('with its properties', function (object $anonymousClass, array $expected) use ($transformer): void {
            $actual = $transformer->transform($anonymousClass)->p['|props'];

            expect($actual)->toEqualCanonicalizing($expected);
        })
            ->with(function (): Generator {
                yield 'public/protected/private property on anonymous class itself' => [
                    new class {
                        public string $public = 'public';
                        protected string $protected = 'protected';
                        private string $private = 'private';
                    },
                    [
                        'public' => 'public',
                        'protected' => 'protected',
                        'private' => 'private',
                    ],
                ];

                // yield 'public/protected/private static property on anonymous class itself' => [
                //     new class {
                //         public static string $public = 'public';
                //         protected static string $protected = 'protected';
                //         private static string $private = 'private';
                //     },
                //     [
                //         'public' => 'public',
                //         'protected' => 'protected',
                //         'private' => 'private',
                //     ],
                // ];

                yield 'public/protected/private promoted property on anonymous class itself' => [
                    new class {
                        public function __construct(
                            public string $public = 'public',
                            protected string $protected = 'protected',
                            private string $private = 'private',
                        ) {}
                    },
                    [
                        'public' => 'public',
                        'protected' => 'protected',
                        'private' => 'private',
                    ],
                ];

                yield 'public/protected/private property on parent class' => [
                    new class extends BaseClassWithProperties {},
                    [
                        'parentPublic' => 'parent public',
                        'parentProtected' => 'parent protected',
                        BaseClassWithProperties::class . "\0" . 'parentPublic' => 'parent public',
                        BaseClassWithProperties::class . "\0" . 'parentProtected' => 'parent protected',
                        BaseClassWithProperties::class . "\0" . 'parentPrivate' => 'parent private',
                    ],
                ];

                // yield 'public/protected/private static property on parent class' => [
                //     new class extends BaseClassWithStaticProperties {},
                //     [
                //         'parentPublic' => 'static parent public',
                //         'parentProtected' => 'static parent protected',
                //         BaseClassWithStaticProperties::class . "\0" . 'parentPublic' => 'static parent public',
                //         BaseClassWithStaticProperties::class . "\0" . 'parentProtected' => 'static parent protected',
                //         BaseClassWithStaticProperties::class . "\0" . 'parentPrivate' => 'static parent private',
                //     ],
                // ];

                yield 'public/protected/private promoted property on parent class' => [
                    new class extends BaseClassWithPromotedProperties {},
                    [
                        'parentPublic' => 'parent public',
                        'parentProtected' => 'parent protected',
                        BaseClassWithPromotedProperties::class . "\0" . 'parentPublic' => 'parent public',
                        BaseClassWithPromotedProperties::class . "\0" . 'parentProtected' => 'parent protected',
                        BaseClassWithPromotedProperties::class . "\0" . 'parentPrivate' => 'parent private',
                    ],
                ];
            });
    });
});

describe('resolving', function (): void {
    $transformer = new AnonymousClassTransformer(new class implements CodeExtractor {
        public function extract(Reflector $reflection, array $memberNamesToDiscard, string $code): string
        {
            return '%EXPECTED EXTRACTED CODE%';
        }
    });

    test('informs about the value it resolves', function (Stasis $input, bool $expected) use ($transformer): void {
        $actual = $transformer->resolves($input);

        expect($actual)->toBe($expected);
    })
        ->with([
            'stasis with something that is not an anonymous class'  => [new Stasis('not-anonymous-class'), false],
            'stasis with something that is an anonymous class'      => [new Stasis('class@anonymous'), true],
        ]);

    test('fails with invalid input', function (mixed $input) use ($transformer): void {
        $transformer->resolve($input);
    })
        ->throws(SerializerError::class)
        ->with([
            'non-stasis value' => '5',
            'stasis with wrong class name' => new Stasis('5'),
        ]);

    test('fails with invalid code', function () use ($transformer): void {
        $stasis = new Stasis('class@anonymous');
        $stasis->p['|code'] = 'this is not valid php code';
        $stasis->p['|props'] = [];
        $stasis->p['|hash'] = '';

        $transformer->resolve($stasis);
    })
        ->expectException(SerializerError::class);

    test('sets resolved', function () use ($transformer): void {
        $stasis = new Stasis('class@anonymous');
        $stasis->p['|code'] = 'class { public static $a = \'unchanged\'; }';
        $stasis->p['|props'] = ['a' => 7];
        $stasis->p['|hash'] = '';

        $actual = $transformer->resolve($stasis);

        expect($actual::$a)->toBe('unchanged');
    });

    test('static properties are not resolved', function () use ($transformer): void {
        $stasis = new Stasis('class@anonymous');
        $stasis->p['|code'] = 'class { public static $a = \'unchanged\'; }';
        $stasis->p['|props'] = ['a' => 7];
        $stasis->p['|hash'] = '';

        $actual = $transformer->resolve($stasis);

        expect($actual::$a)->toBe('unchanged');
    });
});

/** @internal */
function createAnonymousClass(): object
{
    return new class extends BaseClass implements Stringable, Interface1, Interface2 {
        public string $public = 'public';
        protected string $protected = 'protected';
        private string $private = 'private';
        public static string $publicStatic = 'public static';
        protected static string $protectedStatic = 'protected static';
        private static string $privateStatic = 'private static';

        public function __toString(): string
        {
            return $this->private . static::$privateStatic;
        }
    };
}

/** @internal */
function createOtherAnonymousClass(): object
{
    return new class extends BaseClass implements Stringable, Interface1, Interface2 {
        public string $public = 'public';
        protected string $protected = 'protected';
        private string $private = 'private';
        public static string $publicStatic = 'public static';
        protected static string $protectedStatic = 'protected static';
        private static string $privateStatic = 'private static';

        public function __toString(): string
        {
            return $this->private . static::$privateStatic;
        }
    };
}

/** @internal */
abstract class BaseClass
{
    protected string $parentProtected = 'parent protected';
    protected static string $staticParentProtected = 'static parent protected';
    private string $parentPrivate = 'parent private';
    private static string $staticParentPrivate = 'static parent private';
}

/** @internal */
abstract class BaseClassWithProperties
{
    public string $parentPublic = 'parent public';
    protected string $parentProtected = 'parent protected';
    private string $parentPrivate = 'parent private';
}

/** @internal */
abstract class BaseClassWithStaticProperties
{
    public static string $parentPublic = 'static parent public';
    protected static string $parentProtected = 'static parent protected';
    private static string $parentPrivate = 'static parent private';
}

/** @internal */
abstract class BaseClassWithPromotedProperties
{
    public function __construct(
        public string $parentPublic = 'parent public',
        protected string $parentProtected = 'parent protected',
        private string $parentPrivate = 'parent private',
    ) {}
}

/** @internal */
interface Interface1 {}

/** @internal */
interface Interface2 {}
