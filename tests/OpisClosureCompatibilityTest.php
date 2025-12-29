<?php

declare(strict_types=1);

/**
 * Tests for compatibility with edge cases found in opis/closure issues.
 *
 * These tests ensure Serializor handles the same edge cases that users
 * reported as bugs in opis/closure, providing confidence for migration.
 *
 * @see https://github.com/opis/closure/issues
 */

namespace Tests;

use Tests\Fixtures\Util;
use Tests\Fixtures\NamespacedFunctions;

// ============================================================================
// Issue #154: Data leaking between arrays due to reference bugs
// https://github.com/opis/closure/issues/154
// ============================================================================

test('opis issue #154: no data leaking between unrelated arrays', function (): void {
    $obj = new class {
        public array $items = [
            ['fn' => 'John', 'ln' => 'Doe'],
        ];
    };

    $data = [
        'item1' => $obj,
        'item2' => [],
        'item3' => ['my data'],
    ];

    $result = Util::s($data);

    // item3 should contain its original data, not data from item1's object
    expect($result['item3'])->toBe(['my data']);
    expect($result['item1']->items)->toBe([['fn' => 'John', 'ln' => 'Doe']]);
    expect($result['item2'])->toBe([]);
});

test('opis issue #154: arrays with similar structure stay independent', function (): void {
    $data = [
        'a' => ['x' => 1, 'y' => 2],
        'b' => ['x' => 3, 'y' => 4],
        'c' => ['x' => 5, 'y' => 6],
    ];

    $result = Util::s($data);

    expect($result['a'])->toBe(['x' => 1, 'y' => 2]);
    expect($result['b'])->toBe(['x' => 3, 'y' => 4]);
    expect($result['c'])->toBe(['x' => 5, 'y' => 6]);
});

test('opis issue #154: object properties do not leak into sibling array elements', function (): void {
    $obj1 = new \stdClass();
    $obj1->data = ['internal' => 'value1'];

    $obj2 = new \stdClass();
    $obj2->data = ['internal' => 'value2'];

    $container = [
        'first' => $obj1,
        'second' => $obj2,
        'third' => ['external' => 'value3'],
    ];

    $result = Util::s($container);

    expect($result['first']->data)->toBe(['internal' => 'value1']);
    expect($result['second']->data)->toBe(['internal' => 'value2']);
    expect($result['third'])->toBe(['external' => 'value3']);
});

// ============================================================================
// Issue #136: Named parameters inside closures
// https://github.com/opis/closure/issues/136
// ============================================================================

test('opis issue #136: closure using named parameters in function calls', function (): void {
    $fn = function (string $foo, string $bar): string {
        return implode(separator: ', ', array: [$foo, $bar]);
    };

    $result = Util::s($fn);

    expect($result('hello', 'world'))->toBe('hello, world');
});

test('opis issue #136: closure with named parameters in array_map', function (): void {
    $fn = function (array $items): array {
        return array_map(callback: fn($x) => $x * 2, array: $items);
    };

    $result = Util::s($fn);

    expect($result([1, 2, 3]))->toBe([2, 4, 6]);
});

test('opis issue #136: closure with named parameters in constructor', function (): void {
    $fn = function (): \DateTime {
        return new \DateTime(datetime: '2024-06-15');
    };

    $result = Util::s($fn);

    expect($result()->format('Y-m-d'))->toBe('2024-06-15');
});

test('opis issue #136: closure with mixed positional and named parameters', function (): void {
    $fn = function (string $value): string {
        return str_replace('a', replace: 'x', subject: $value);
    };

    $result = Util::s($fn);

    expect($result('abracadabra'))->toBe('xbrxcxdxbrx');
});

test('opis issue #136: arrow function with named parameters', function (): void {
    $fn = fn(array $arr) => array_filter(array: $arr, callback: fn($v) => $v > 0);

    $result = Util::s($fn);

    expect(array_values($result([-1, 0, 1, 2, -3])))->toBe([1, 2]);
});

// ============================================================================
// Issue #112: Closure::fromCallable with user-defined functions
// https://github.com/opis/closure/issues/112
// ============================================================================

test('opis issue #112: Closure::fromCallable with global user function', function (): void {
    // Define a simple function in global scope for testing
    $fn = \Closure::fromCallable('array_sum');

    $result = Util::s($fn);

    expect($result([1, 2, 3, 4]))->toBe(10);
});

test('opis issue #112: Closure::fromCallable with instance method', function (): void {
    $obj = new class {
        public function greet(string $name): string {
            return "Hello, $name!";
        }
    };

    $fn = \Closure::fromCallable([$obj, 'greet']);

    $result = Util::s($fn);

    expect($result('World'))->toBe('Hello, World!');
});

test('opis issue #112: Closure::fromCallable with invokable object', function (): void {
    $invokable = new class {
        public function __invoke(int $x): int {
            return $x * 2;
        }
    };

    $fn = \Closure::fromCallable($invokable);

    $result = Util::s($fn);

    expect($result(21))->toBe(42);
});

// ============================================================================
// Issue #78: Namespaced functions in closures
// https://github.com/opis/closure/issues/78
// ============================================================================

test('opis issue #78: closure calling function from same namespace', function (): void {
    // The closure is defined in the Tests\Fixtures namespace and calls
    // a function from that namespace
    $fn = NamespacedFunctions::getClosureCallingNamespacedFunction();

    $result = Util::s($fn);

    expect($result('test'))->toBe('namespaced: test');
});

test('opis issue #78: closure with use function import', function (): void {
    $fn = NamespacedFunctions::getClosureWithUseFunctionImport();

    $result = Util::s($fn);

    expect($result('hello'))->toBe('namespaced: hello');
});

test('opis issue #78: closure calling fully qualified function', function (): void {
    $fn = function (string $value): int {
        return \strlen($value);
    };

    $result = Util::s($fn);

    expect($result('hello'))->toBe(5);
});

// ============================================================================
// Issue #62: Array access in string interpolation
// https://github.com/opis/closure/issues/62
// ============================================================================

test('opis issue #62: simple array access in string interpolation', function (): void {
    $fn = function (): string {
        $a = ['foo' => 'bar'];
        return "Value: $a[foo]";
    };

    $result = Util::s($fn);

    expect($result())->toBe('Value: bar');
});

test('opis issue #62: ArrayObject access in string interpolation', function (): void {
    $fn = function (): string {
        $a = new \ArrayObject(['foo' => 'bar']);
        return "Value: {$a['foo']}";
    };

    $result = Util::s($fn);

    expect($result())->toBe('Value: bar');
});

test('opis issue #62: numeric array access in string interpolation', function (): void {
    $fn = function (): string {
        $arr = ['zero', 'one', 'two'];
        return "First: $arr[0], Second: $arr[1]";
    };

    $result = Util::s($fn);

    expect($result())->toBe('First: zero, Second: one');
});

test('opis issue #62: complex curly brace interpolation', function (): void {
    $fn = function (): string {
        $data = ['user' => ['name' => 'Alice', 'age' => 30]];
        return "User: {$data['user']['name']}, Age: {$data['user']['age']}";
    };

    $result = Util::s($fn);

    expect($result())->toBe('User: Alice, Age: 30');
});

test('opis issue #62: object property in string interpolation', function (): void {
    $fn = function (): string {
        $obj = new \stdClass();
        $obj->name = 'Bob';
        return "Hello, $obj->name!";
    };

    $result = Util::s($fn);

    expect($result())->toBe('Hello, Bob!');
});

// ============================================================================
// Issue #95: $this in nested closures
// https://github.com/opis/closure/issues/95
// ============================================================================

test('opis issue #95: $this accessible in nested closure', function (): void {
    $obj = new class {
        public int $id = 42;

        public function getNestedClosure(): \Closure {
            return function (): \Closure {
                return function (): int {
                    return $this->id;
                };
            };
        }
    };

    $outerFn = $obj->getNestedClosure();
    $result = Util::s($outerFn);

    $innerFn = $result();
    expect($innerFn())->toBe(42);
});

test('opis issue #95: $this in deeply nested closures', function (): void {
    $obj = new class {
        public string $value = 'deep';

        public function getDeeplyNestedClosure(): \Closure {
            return function (): \Closure {
                return function (): \Closure {
                    return function (): string {
                        return $this->value;
                    };
                };
            };
        }
    };

    $fn = $obj->getDeeplyNestedClosure();
    $result = Util::s($fn);

    expect($result()()())->toBe('deep');
});

test('opis issue #95: $this in closure returned by method', function (): void {
    $obj = new class {
        private string $secret = 'hidden';

        public function getAccessor(): \Closure {
            return fn() => $this->secret;
        }
    };

    $fn = $obj->getAccessor();
    $result = Util::s($fn);

    expect($result())->toBe('hidden');
});

// ============================================================================
// Issue #129: Readonly properties with closures (additional tests)
// https://github.com/opis/closure/issues/129
// ============================================================================

test('opis issue #129: object with readonly closure assigned in constructor', function (): void {
    $obj = new class {
        public readonly \Closure $handler;

        public function __construct() {
            $this->handler = fn(int $x) => $x * 2;
        }
    };

    $result = Util::s($obj);

    expect(($result->handler)(21))->toBe(42);
});

test('opis issue #129: readonly property with closure capturing $this', function (): void {
    $obj = new class {
        public readonly \Closure $selfRef;
        public string $name = 'TestObject';

        public function __construct() {
            $this->selfRef = fn() => $this->name;
        }
    };

    $result = Util::s($obj);

    expect(($result->selfRef)())->toBe('TestObject');
});

test('opis issue #129: multiple readonly closure properties', function (): void {
    $obj = new class {
        public readonly \Closure $add;
        public readonly \Closure $multiply;

        public function __construct() {
            $this->add = fn(int $a, int $b) => $a + $b;
            $this->multiply = fn(int $a, int $b) => $a * $b;
        }
    };

    $result = Util::s($obj);

    expect(($result->add)(3, 4))->toBe(7);
    expect(($result->multiply)(3, 4))->toBe(12);
});

// ============================================================================
// Issue #61: Static closure modifier (additional tests)
// https://github.com/opis/closure/issues/61
// ============================================================================

test('opis issue #61: static keyword as closure modifier not confused with scope', function (): void {
    $fn = function (): \Closure {
        $callback = static function (int $retries): int {
            return 750 * $retries;
        };
        return $callback;
    };

    $result = Util::s($fn);
    $callback = $result();

    expect($callback(3))->toBe(2250);

    // Verify it's actually static
    $rf = new \ReflectionFunction($callback);
    expect($rf->isStatic())->toBeTrue();
});

test('opis issue #61: static arrow function inside regular closure', function (): void {
    $fn = function (): \Closure {
        return static fn(int $x) => $x * $x;
    };

    $result = Util::s($fn);
    $inner = $result();

    expect($inner(5))->toBe(25);

    $rf = new \ReflectionFunction($inner);
    expect($rf->isStatic())->toBeTrue();
});

// ============================================================================
// Issue #118: DateTime initialization
// https://github.com/opis/closure/issues/118
// ============================================================================

test('opis issue #118: DateTime in closure use variable', function (): void {
    $date = new \DateTime('2024-06-15 14:30:00');
    $fn = function () use ($date): string {
        return $date->format('Y-m-d H:i:s');
    };

    $result = Util::s($fn);

    expect($result())->toBe('2024-06-15 14:30:00');
});

test('opis issue #118: DateTimeImmutable in closure', function (): void {
    $date = new \DateTimeImmutable('2024-12-25');
    $fn = function () use ($date): string {
        return $date->format('Y-m-d');
    };

    $result = Util::s($fn);

    expect($result())->toBe('2024-12-25');
});

test('opis issue #118: DateTime with timezone', function (): void {
    $tz = new \DateTimeZone('America/New_York');
    $date = new \DateTime('2024-06-15 12:00:00', $tz);

    $fn = function () use ($date): array {
        return [
            'date' => $date->format('Y-m-d H:i:s'),
            'tz' => $date->getTimezone()->getName(),
        ];
    };

    $result = Util::s($fn);
    $r = $result();

    expect($r['date'])->toBe('2024-06-15 12:00:00');
    expect($r['tz'])->toBe('America/New_York');
});

// ============================================================================
// Issue #91: __DIR__ resolution (additional tests)
// https://github.com/opis/closure/issues/91
// ============================================================================

test('opis issue #91: __DIR__ preserved after serialization', function (): void {
    $originalDir = __DIR__;
    $fn = fn() => __DIR__;

    $result = Util::s($fn);

    // After unserialization, __DIR__ should still return the original directory
    expect($result())->toBe($originalDir);
});

test('opis issue #91: __DIR__ in path concatenation', function (): void {
    $fn = function (): string {
        return __DIR__ . '/fixtures/test.txt';
    };

    $result = Util::s($fn);

    expect($result())->toBe(__DIR__ . '/fixtures/test.txt');
});

// ============================================================================
// Additional edge cases from opis/closure issues
// ============================================================================

test('closure with multiple string interpolation types', function (): void {
    $fn = function (): string {
        $name = 'Alice';
        $data = ['city' => 'Paris'];
        $obj = new \stdClass();
        $obj->country = 'France';

        return "$name lives in $data[city], $obj->country";
    };

    $result = Util::s($fn);

    expect($result())->toBe('Alice lives in Paris, France');
});

test('closure with heredoc containing variables', function (): void {
    $fn = function (string $name, int $age): string {
        return <<<EOT
        Name: $name
        Age: $age
        EOT;
    };

    $result = Util::s($fn);

    expect($result('Bob', 25))->toContain('Name: Bob');
    expect($result('Bob', 25))->toContain('Age: 25');
});

test('closure with complex array operations', function (): void {
    $fn = function (array $data): array {
        return array_map(
            callback: fn($item) => [
                'original' => $item,
                'doubled' => $item * 2,
            ],
            array: array_filter(
                array: $data,
                callback: fn($v) => $v > 0,
            ),
        );
    };

    $result = Util::s($fn);

    $output = $result([-1, 2, 0, 3, -4, 5]);
    expect(count($output))->toBe(3);
    expect($output[1]['doubled'])->toBe(4);
    expect($output[3]['doubled'])->toBe(6);
    expect($output[5]['doubled'])->toBe(10);
});
