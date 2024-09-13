<?php

declare(strict_types=1);

namespace Tests\Unit\Transformers;

use Closure;
use Generator;
use ReflectionClass;
use Serializor\SerializerError;
use Serializor\Stasis;
use Serializor\Transformers\ClosureTransformer;

use function printf;

covers(ClosureTransformer::class);

describe('transforming', function (): void {
    test('informs about the value it transforms', function (mixed $input, bool $expected): void {
        $transformer = new ClosureTransformer();

        $actual = $transformer->transforms($input);

        expect($actual)->toBe($expected);
    })
        ->with([
            'string'  => ['1', false],
            'int'     => [2, false],
            'array'   => [[3], false],
            'object'  => [(object) [4], false],
            'closure' => [fn() => 5, true],
        ]);

    test('transforms `Closures` into `Stasis`', function (Closure $input): void {
        $transformer = new ClosureTransformer();

        $actual = $transformer->transform($input);

        expect($actual)->toBeInstanceOf(Stasis::class);
    })
        ->with(static function (): Generator {
            yield 'short closure' => fn(): null => null;
            yield 'static short closure' => static fn(): null => null;
            yield 'closure' => function (): null {
                return null;
            };
            yield 'static closure' => static function (): null {
                return null;
            };
            yield 'user defined function' => namedClosure(...);
            yield 'user defined method' => (new A())->a(...);
            yield 'static user defined method' => (new A())->b(...);
            yield 'native function' => printf(...);
            yield 'native method' => (new ReflectionClass(Closure::class))->getAttributes(...);
            yield 'static native method' => Closure::fromCallable(...);
            $a = 5;
            yield 'closure with use component' => function () use ($a): mixed {
                return $a;
            };
            yield 'static closure with use component' => static function () use ($a): mixed {
                return $a;
            };
        });

    test('transforms the same `Closures` into the same `Stasis`', function (): void {
        $input = static fn(): null => null;
        $transformer = new ClosureTransformer();

        $actual = $transformer->transform($input);
        $expected = $transformer->transform($input);

        expect($actual)->toBe($expected);
    });

    describe('generated stasis stores basic information about closure', function (): void {
        $transformer = new ClosureTransformer();

        test('name', function () use ($transformer): void {
            $actual = $transformer->transform(namedClosure(...));

            expect($actual->p['name'])->toBe(__NAMESPACE__ . '\namedClosure');
        });

        test('hash', function () use ($transformer): void {
            $actual = $transformer->transform(static fn(): null => null);

            expect($actual->p['hash'])->toBeString();
        });

        test('hash for different instances is different', function () use ($transformer): void {
            $hash1 = $transformer->transform(static fn(): null => null)->p['hash'];
            $hash2 = $transformer->transform(static fn(): null => null)->p['hash'];

            expect($hash1)->not()->toBe($hash2);
        });

        test('callable', function () use ($transformer): void {
            $actual = $transformer->transform(namedClosure(...));

            expect($actual->p['callable'])->toBeCallable();
        })
            ->note('A stasis for an anonymous function does not return a valid callable');

        test('no this for free functions', function () use ($transformer): void {
            $actual = $transformer->transform(namedClosure(...));

            expect($actual->p['this'])->toBeNull();
        });

        test('this', function () use ($transformer): void {
            $actual = $transformer->transform(function () {
                $this;
            });

            expect($actual->p['this'])->toBe($this);
        });

        test('no scope class for free functions', function () use ($transformer): void {
            $actual = $transformer->transform(namedClosure(...));

            expect($actual->p['scope_class'])->toBeNull();
        });

        test('scope class', function () use ($transformer): void {
            $actual = $transformer->transform(function () {
                $this;
            });

            expect($actual->p['scope_class'])->toBe($this::class);
        });

        test('no called class for free functions', function () use ($transformer): void {
            $actual = $transformer->transform(namedClosure(...));

            expect($actual->p['called_class'])->toBeNull();
        });

        test('called class', function () use ($transformer): void {
            $actual = $transformer->transform(function () {
                $this;
            });

            expect($actual->p['called_class'])->toBe($this::class);
        });

        test('namespace', function () use ($transformer): void {
            $actual = $transformer->transform(namedClosure(...));

            expect($actual->p['namespace'])->toBe(__NAMESPACE__);
        });

        test('used variables are stored in stasis', function () use ($transformer): void {
            $used = 5;
            $variable = 6;
            $input = function () use ($used, $variable): void {};

            $actual = $transformer->transform($input);

            expect($actual->p['use'])->toEqual(['used' => $used, 'variable' => $variable]);
        });

        test('used variables can be transformed', function (): void {
            $transformUseVariablesFunc = static fn(array $variables): mixed => [
                'used' => ++$variables['used'],
                'variable' => --$variables['variable'],
            ];
            $used = 0;
            $variable = 1;
            $input = function () use ($used, $variable): void {};
            $transformer = new ClosureTransformer(
                transformUseVariablesFunc: $transformUseVariablesFunc
            );

            $actual = $transformer->transform($input);

            expect($actual->p['use'])->toEqual(['used' => 1, 'variable' => 0]);
        });
    });

    test('transforms only closures', function (): void {
        $input = 'not-a-closure';
        $transformer = new ClosureTransformer();

        $actual = $transformer->transform($input);

        expect($actual)->toBeFalse();
    });
});

describe('resolving', function (): void {
    $transformer = new ClosureTransformer();

    test('informs about the value it resolves', function (Stasis $input, bool $expected) use ($transformer): void {
        $actual = $transformer->resolves($input);

        expect($actual)->toBe($expected);
    })
        ->with([
            'stasis with something that is not a closure'  => [new Stasis('not-closure'), false],
            'stasis with something that is a closure'      => [new Stasis(Closure::class), true],
        ]);

    test('fails with invalid input', function (mixed $input) use ($transformer): void {
        $transformer->resolve($input);
    })
        ->throws(SerializerError::class)
        ->with([
            'non-stasis value' => '5',
            'stasis with wrong class name' => new Stasis('5'),
        ]);

    test('a callable correctly', function () use ($transformer): void {
        $stasis = new Stasis(Closure::class);
        $stasis->p['callable'] = __NAMESPACE__ . '\namedClosure';
        $expected = namedClosure(...);

        $actual = $transformer->resolve($stasis);

        expect($actual)->toEqual($expected);
    });

    test('a callable correctly to the same object twice', function () use ($transformer): void {
        $stasis = new Stasis(Closure::class);
        $stasis->p['callable'] = __NAMESPACE__ . '\namedClosure';

        $actual1 = $transformer->resolve($stasis);
        $actual2 = $transformer->resolve($stasis);

        expect($actual1)->toBe($actual2);
    });

    test('a private static callable correctly', function () use ($transformer): void {
        $stasis = new Stasis(Closure::class);
        $stasis->p['callable'] = [__NAMESPACE__ . '\\A', 'd'];

        $actual = $transformer->resolve($stasis);

        expect($actual)->toBeInstanceOf(Closure::class);
    });

    test('a closure from code', function () use ($transformer): void {
        $stasis = new Stasis(Closure::class);
        $stasis->p['callable'] = null;
        $stasis->p['this'] = null;
        $stasis->p['scope_class'] = 'static';
        $stasis->p['called_class'] = 'static';
        $stasis->p['namespace'] = __NAMESPACE__;
        $stasis->p['code'] = 'function () use ($usedVariable) {return $usedVariable;}';
        $stasis->p['use'] = ['usedVariable' => 5];

        $actual = $transformer->resolve($stasis);

        expect($actual())->toBe(5);
    });

    test('used variables can be resolved', function (): void {
        $resolveUseVariablesFunc = static fn(array $variables): mixed => [
            'used' => ++$variables['used'],
            'variable' => --$variables['variable'],
        ];
        $stasis = new Stasis(Closure::class);
        $stasis->p['callable'] = null;
        $stasis->p['this'] = null;
        $stasis->p['scope_class'] = 'static';
        $stasis->p['called_class'] = 'static';
        $stasis->p['namespace'] = __NAMESPACE__;
        $stasis->p['code'] = 'function () use ($used, $variable) {return [\'used\' => $used, \'variable\' => $variable];}';
        $stasis->p['use'] = ['used' => 0, 'variable' => 1];
        $transformer = new ClosureTransformer(
            resolveUseVariablesFunc: $resolveUseVariablesFunc
        );

        $actual = $transformer->resolve($stasis);

        expect($actual())->toEqual(['used' => 1, 'variable' => 0]);
    });
});

/** @internal */
function namedClosure(): void {};

/** @internal */
final class A
{
    public function a(): void {}

    public static function b(): void {}

    private function c(): void {}

    private static function d(): void {}
}
