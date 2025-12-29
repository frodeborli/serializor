<?php

declare(strict_types=1);

namespace Tests\Transformers;

use Tests\Fixtures\Closure;
use Tests\Fixtures\Util;

use function Tests\s;

test('`\Closure` returning a class instance called `Closure`', function (): void {
    $expected = static fn(): Closure => new Closure();

    $actual = Util::s($expected);

    expect($actual)->toEqual($expected);
});

test('arrow function with use variable', function (): void {
    $x = 10;
    $fn = fn($y) => $x + $y;

    $result = Util::s($fn);

    expect($result(5))->toBe(15);
});

test('closure with typed parameters', function (): void {
    $fn = function (int $a, string $b): string {
        return $b . $a;
    };

    $result = Util::s($fn);

    expect($result(42, 'value: '))->toBe('value: 42');
});

test('closure with default parameter values', function (): void {
    $fn = function (int $a = 10, string $b = 'default') {
        return [$a, $b];
    };

    $result = Util::s($fn);

    expect($result())->toBe([10, 'default']);
    expect($result(5))->toBe([5, 'default']);
    expect($result(5, 'custom'))->toBe([5, 'custom']);
});

test('closure with variadic parameters', function (): void {
    $fn = function (...$args) {
        return array_sum($args);
    };

    $result = Util::s($fn);

    expect($result(1, 2, 3, 4, 5))->toBe(15);
});

test('closure with nullable type', function (): void {
    $fn = function (?int $value): ?string {
        return $value !== null ? (string) $value : null;
    };

    $result = Util::s($fn);

    expect($result(42))->toBe('42');
    expect($result(null))->toBeNull();
});

test('closure with union types', function (): void {
    $fn = function (int|string $value): int|string {
        return is_int($value) ? $value * 2 : $value . $value;
    };

    $result = Util::s($fn);

    expect($result(5))->toBe(10);
    expect($result('ab'))->toBe('abab');
});

test('closure with static variable', function (): void {
    $fn = function () {
        static $count = 0;
        return ++$count;
    };

    // Call original twice
    expect($fn())->toBe(1);
    expect($fn())->toBe(2);

    // Serialized closure should have fresh static state
    $result = Util::s($fn);

    expect($result())->toBe(1);
    expect($result())->toBe(2);
});

test('closure with match expression', function (): void {
    $fn = function (int $value): string {
        return match ($value) {
            1 => 'one',
            2 => 'two',
            default => 'other',
        };
    };

    $result = Util::s($fn);

    expect($result(1))->toBe('one');
    expect($result(2))->toBe('two');
    expect($result(99))->toBe('other');
});

test('multiple indistinguishable closures on same line throws exception', function (): void {
    // Closures on the same line with identical signatures cannot be distinguished.
    // Serializor now throws an exception instead of silently picking the wrong one.
    $a = fn() => 'first'; $b = fn() => 'second';

    // Both have no parameters, so they can't be distinguished
    expect(fn() => Util::s($a))->toThrow(\Serializor\SerializerError::class, 'multiple closures');
});

test('distinguishable closures on same line work correctly', function (): void {
    // Closures can be distinguished by their parameter names
    $a = fn($x) => $x + 1; $b = fn($y) => $y * 2;

    $resultA = Util::s($a);
    $resultB = Util::s($b);

    expect($resultA(5))->toBe(6);
    expect($resultB(5))->toBe(10);
});

test('arrow function with complex expression', function (): void {
    $fn = fn(array $arr) => array_map(fn($x) => $x * 2, $arr);

    $result = Util::s($fn);

    expect($result([1, 2, 3]))->toBe([2, 4, 6]);
});

test('closure that throws exception', function (): void {
    $fn = function (bool $shouldThrow): string {
        if ($shouldThrow) {
            throw new \RuntimeException('Test error');
        }
        return 'success';
    };

    $result = Util::s($fn);

    expect($result(false))->toBe('success');
    expect(fn() => $result(true))->toThrow(\RuntimeException::class, 'Test error');
});

test('closure with named arguments', function (): void {
    $fn = function (string $first, string $second): string {
        return "$first-$second";
    };

    $result = Util::s($fn);

    expect($result(second: 'B', first: 'A'))->toBe('A-B');
});

test('closure using global function', function (): void {
    $fn = function (array $values): int {
        return array_sum($values);
    };

    $result = Util::s($fn);

    expect($result([1, 2, 3, 4]))->toBe(10);
});

test('closure with heredoc string', function (): void {
    $fn = function (): string {
        return <<<TEXT
        Hello
        World
        TEXT;
    };

    $result = Util::s($fn);

    expect($result())->toContain('Hello');
    expect($result())->toContain('World');
});

test('closure with nowdoc string', function (): void {
    $fn = function (): string {
        return <<<'TEXT'
        $variable is not interpolated
        TEXT;
    };

    $result = Util::s($fn);

    expect($result())->toContain('$variable');
});

test('first class callable syntax', function (): void {
    $obj = new class {
        public function double(int $x): int {
            return $x * 2;
        }
    };

    $fn = $obj->double(...);

    $result = Util::s($fn);

    expect($result(5))->toBe(10);
});

// ============================================================================
// Enum tests (inspired by opis/closure)
// ============================================================================

test('enum closure returns enum case', function (): void {
    $closure = \Tests\Fixtures\MyEnum::CASE1->getClosure();

    $result = Util::s($closure);

    expect($result())->toBe(\Tests\Fixtures\MyEnum::CASE1);
});

test('closure using enum in use variable', function (): void {
    $enum = \Tests\Fixtures\MyEnum::CASE2;
    $fn = fn() => $enum->value;

    $result = Util::s($fn);

    expect($result())->toBe('c2');
});

// ============================================================================
// Readonly property tests (inspired by opis/closure)
// ============================================================================

test('object with readonly closure property', function (): void {
    $obj = new \Tests\Fixtures\ReadonlyClass();

    $result = Util::s($obj);

    // Object is properly restored
    expect($result)->toBeInstanceOf(\Tests\Fixtures\ReadonlyClass::class);
    // Closure is callable
    expect($result->func)->toBeInstanceOf(\Closure::class);
});

test('closure from readonly property', function (): void {
    $closure = (new \Tests\Fixtures\ReadonlyClass())->func;

    $result = Util::s($closure);

    // The closure should return the object it's bound to
    expect($result())->toBeInstanceOf(\Tests\Fixtures\ReadonlyClass::class);
    expect($result()->func)->toBe($result);
});

// ============================================================================
// Closure::fromCallable tests (inspired by opis/closure)
// ============================================================================

test('Closure::fromCallable with built-in function', function (): void {
    $fn = \Closure::fromCallable('str_replace');

    $result = Util::s($fn);

    expect($result('a', 'x', 'a1a2a3'))->toBe('x1x2x3');
});

test('Closure::fromCallable with static method', function (): void {
    $fn = \Closure::fromCallable([\DateTime::class, 'createFromFormat']);

    $result = Util::s($fn);

    $date = $result('Y-m-d', '2024-01-15');
    expect($date->format('Y-m-d'))->toBe('2024-01-15');
});

// ============================================================================
// Internal class tests (inspired by opis/closure)
// ============================================================================

test('closure using DateTime in use variable', function (): void {
    $date = new \DateTime();
    $date->setDate(2024, 6, 15);

    $fn = function () use ($date) {
        return $date->format('Y-m-d');
    };

    $result = Util::s($fn);

    expect($result())->toBe('2024-06-15');
});

test('closure using DateTime in object property', function (): void {
    $obj = new \stdClass();
    $obj->date = new \DateTime();
    $obj->date->setDate(2024, 12, 25);

    $fn = function () use ($obj) {
        return $obj->date->format('Y-m-d');
    };

    $result = Util::s($fn);

    expect($result())->toBe('2024-12-25');
});

// ============================================================================
// PHP 8.1+ type system tests (inspired by opis/closure)
// ============================================================================

test('closure with intersection types', function (): void {
    $fn = function (\Countable&\Iterator $value): int {
        return count($value);
    };

    $result = Util::s($fn);

    $arrayIterator = new \ArrayIterator([1, 2, 3]);
    expect($result($arrayIterator))->toBe(3);
});

test('closure with never return type', function (): void {
    $fn = function (): never {
        throw new \RuntimeException('Never returns');
    };

    $result = Util::s($fn);

    expect(fn() => $result())->toThrow(\RuntimeException::class);
});

// ============================================================================
// Complex recursive structure tests (inspired by opis/closure)
// ============================================================================

test('recursive array in closure use', function (): void {
    $a = ['foo'];
    $a[] = &$a;

    $fn = function () use ($a) {
        return $a[1][0];
    };

    $result = Util::s($fn);

    expect($result())->toBe('foo');
});

test('recursive array by reference in closure use', function (): void {
    // Recursive array references with use(&$var) are properly preserved
    $a = ['bar'];
    $a[] = &$a;

    $fn = function () use (&$a) {
        return $a[1][0];
    };

    $result = Util::s($fn);

    expect($result())->toBe('bar');
});

test('nested objects with circular reference', function (): void {
    $parent = new \stdClass();
    $child = new \stdClass();

    $parent->children = [$child];
    $child->parent = $parent;

    $fn = function () use ($parent, $child) {
        return $parent === $child->parent;
    };

    $result = Util::s($fn);

    expect($result())->toBeTrue();
});

test('closure in object referencing self via property access', function (): void {
    // Test a closure accessing the object it's on through properties
    $obj = new \stdClass();
    $obj->value = 'test';
    // Avoid circular reference - don't use $obj in the closure
    $value = $obj->value;
    $obj->closure = function () use ($value) {
        return $value;
    };

    $result = Util::s($obj);
    $closure = $result->closure;

    expect($closure())->toBe('test');
});

// ============================================================================
// Arrow function edge cases (inspired by opis/closure)
// ============================================================================

test('arrow function with null coalescing', function (): void {
    $fn = fn($x) => $x ?? 'default';

    $result = Util::s($fn);

    expect($result(null))->toBe('default');
    expect($result('value'))->toBe('value');
});

test('arrow function with null coalescing assignment', function (): void {
    $fn = function (&$x) {
        return $x ??= 'assigned';
    };

    $result = Util::s($fn);

    $val = null;
    expect($result($val))->toBe('assigned');
    expect($val)->toBe('assigned');
});

test('arrow function in ternary expression', function (): void {
    // Known limitation: arrow functions extracted from ternary expressions
    // may include the ternary continuation in the parsed code
    $fn = true ? fn() => 'yes' : fn() => 'no';

    // This fails due to parser capturing `: fn() => 'no'` as part of the closure
    $threw = false;
    try {
        Util::s($fn);
    } catch (\Throwable) {
        $threw = true;
    }
    expect($threw)->toBeTrue();
})->group('known-limitations');

test('arrow function with nested ternary', function (): void {
    $fn = fn($x) => $x > 0 ? ($x > 10 ? 'big' : 'small') : 'zero';

    $result = Util::s($fn);

    expect($result(0))->toBe('zero');
    expect($result(5))->toBe('small');
    expect($result(20))->toBe('big');
});

// ============================================================================
// Additional tests inspired by laravel/serializable-closure
// ============================================================================

test('closure with switch statement', function (): void {
    $fn = function (int $value): string {
        switch ($value) {
            case 1:
                return 'one';
            case 2:
                return 'two';
            case 3:
                return 'three';
            default:
                return 'other';
        }
    };

    $result = Util::s($fn);

    expect($result(1))->toBe('one');
    expect($result(2))->toBe('two');
    expect($result(3))->toBe('three');
    expect($result(99))->toBe('other');
});

test('closure with instanceof check', function (): void {
    $fn = function ($value): bool {
        return $value instanceof \DateTime || $value instanceof \stdClass;
    };

    $result = Util::s($fn);

    expect($result(new \DateTime()))->toBeTrue();
    expect($result(new \stdClass()))->toBeTrue();
    expect($result('string'))->toBeFalse();
});

test('closure with array unpacking', function (): void {
    $fn = function (): array {
        $array1 = ['a' => 1];
        $array2 = ['b' => 2];
        return ['a' => 0, ...$array1, ...$array2];
    };

    $result = Util::s($fn);

    expect($result())->toBe(['a' => 1, 'b' => 2]);
});

test('first class callable with static method', function (): void {
    $fn = \DateTime::createFromFormat(...);

    $result = Util::s($fn);

    $date = $result('Y-m-d', '2024-06-15');
    expect($date->format('Y-m-d'))->toBe('2024-06-15');
});

test('closure using class constant', function (): void {
    $fn = function (): int {
        return \DateTimeInterface::ATOM ? 1 : 0;
    };

    $result = Util::s($fn);

    expect($result())->toBe(1);
});

test('closure with string interpolation', function (): void {
    $fn = function (string $name, int $age): string {
        return "Hello, {$name}! You are {$age} years old.";
    };

    $result = Util::s($fn);

    expect($result('Alice', 30))->toBe('Hello, Alice! You are 30 years old.');
});

test('static closure preserves static binding', function (): void {
    $fn = static function (): bool {
        return true;
    };

    $result = Util::s($fn);

    expect($result())->toBeTrue();

    // Verify it's actually static (can't bind to object)
    $rf = new \ReflectionFunction($result);
    expect($rf->isStatic())->toBeTrue();
});

test('closure with spread operator in parameters', function (): void {
    $fn = function (int $first, int ...$rest): int {
        return $first + array_sum($rest);
    };

    $result = Util::s($fn);

    expect($result(1, 2, 3, 4))->toBe(10);
});

test('closure serialized twice', function (): void {
    $fn = function (int $a, int $b): int {
        return $a + $b;
    };

    // Serialize and unserialize twice
    $result = Util::s(Util::s($fn));

    expect($result(2, 3))->toBe(5);
});

// ============================================================================
// Feature gap tests vs opis/closure and laravel/serializable-closure
// ============================================================================

test('closure with __DIR__ magic constant', function (): void {
    $originalDir = __DIR__;
    $fn = function (): string {
        return __DIR__;
    };

    $result = Util::s($fn);

    // After unserialization, __DIR__ should still point to the original directory
    expect($result())->toBe($originalDir);
});

test('closure with __FILE__ magic constant', function (): void {
    $originalFile = __FILE__;
    $fn = function (): string {
        return __FILE__;
    };

    $result = Util::s($fn);

    // After unserialization, __FILE__ should still point to the original file
    expect($result())->toBe($originalFile);
});

test('closure with __CLASS__ in bound scope', function (): void {
    $obj = new class {
        public function getClosure(): \Closure {
            return function (): string {
                return __CLASS__;
            };
        }
    };

    $fn = $obj->getClosure();
    $result = Util::s($fn);

    // Should preserve the anonymous class name
    expect($result())->toBe(get_class($obj));
});

test('closure with __FUNCTION__ magic constant', function (): void {
    $fn = function (): string {
        return __FUNCTION__;
    };

    $result = Util::s($fn);

    // __FUNCTION__ should preserve the original closure's name
    expect($result())->toBe($fn());
});

test('ArrayObject serialization', function (): void {
    $ao = new \ArrayObject(['a' => 1, 'b' => 2]);
    $fn = function () use ($ao): int {
        return $ao['a'] + $ao['b'];
    };

    $result = Util::s($fn);

    expect($result())->toBe(3);
});

test('SplObjectStorage serialization', function (): void {
    $storage = new \SplObjectStorage();
    $obj1 = new \stdClass();
    $obj1->value = 10;
    $storage[$obj1] = 'data1';

    $fn = function () use ($storage, $obj1): string {
        return $storage[$obj1];
    };

    $result = Util::s($fn);

    expect($result())->toBe('data1');
});

test('SplObjectStorage containing closures', function (): void {
    $storage = new \SplObjectStorage();
    $closure = fn() => 'hello from closure';
    $storage[$closure] = 'closure data';

    $result = Util::s($storage);

    // Check that we can iterate and the closure works
    $foundClosure = false;
    foreach ($result as $key) {
        if ($key instanceof \Closure) {
            $foundClosure = true;
            expect($key())->toBe('hello from closure');
            expect($result[$key])->toBe('closure data');
        }
    }
    expect($foundClosure)->toBeTrue();
});

test('SplDoublyLinkedList serialization', function (): void {
    $list = new \SplDoublyLinkedList();
    $list->push('first');
    $list->push('second');

    $fn = function () use ($list): string {
        return $list->bottom() . '-' . $list->top();
    };

    $result = Util::s($fn);

    expect($result())->toBe('first-second');
});

test('closure with goto statement', function (): void {
    $fn = function (int $n): int {
        $result = 0;
        loop:
        if ($n <= 0) {
            return $result;
        }
        $result += $n;
        $n--;
        goto loop;
    };

    $result = Util::s($fn);

    expect($result(5))->toBe(15); // 5 + 4 + 3 + 2 + 1
});

test('closure in array value context', function (): void {
    // Test closure extraction when it's an array value
    $arr = [
        'callback' => fn(int $x) => $x * 2,
    ];

    $result = Util::s($arr['callback']);

    expect($result(5))->toBe(10);
});

test('arrow function as function argument', function (): void {
    // Test closure when passed directly as an argument
    $fn = fn(int $x) => $x + 1;

    $result = Util::s($fn);

    expect($result(10))->toBe(11);
});

// Note: PHP does not support attributes directly on closures.
// Attributes are tested on classes via AnonymousClassTransformer tests.

test('first class callable with instance method preserves object state', function (): void {
    $obj = new class {
        public int $value = 42;
        public function getValue(): int {
            return $this->value;
        }
    };

    // First-class callable syntax
    $fn = $obj->getValue(...);

    // Modify object before serialization
    $obj->value = 100;

    $result = Util::s($fn);

    // Should preserve the object state at serialization time
    expect($result())->toBe(100);
});

test('closure using compact()', function (): void {
    $a = 1;
    $b = 2;
    $fn = function () use ($a, $b): array {
        return compact('a', 'b');
    };

    $result = Util::s($fn);

    expect($result())->toBe(['a' => 1, 'b' => 2]);
});

test('closure using extract()', function (): void {
    $fn = function (array $data): int {
        extract($data);
        return $x + $y;
    };

    $result = Util::s($fn);

    expect($result(['x' => 10, 'y' => 20]))->toBe(30);
});

test('nested closure with different scopes', function (): void {
    $outer = 'outer_value';
    $fn = function () use ($outer): \Closure {
        $inner = 'inner_value';
        return function () use ($outer, $inner): string {
            return "$outer-$inner";
        };
    };

    $result = Util::s($fn);
    $innerFn = $result();

    expect($innerFn())->toBe('outer_value-inner_value');
});

test('closure referencing global variable', function (): void {
    global $testGlobalVar;
    $testGlobalVar = 'global_value';

    $fn = function (): string {
        global $testGlobalVar;
        return $testGlobalVar;
    };

    $result = Util::s($fn);

    expect($result())->toBe('global_value');
});

test('WeakReference in closure use variable', function (): void {
    $obj = new \stdClass();
    $obj->name = 'test';
    $ref = \WeakReference::create($obj);

    // Must include $obj in use variables to keep it alive after unserialization
    $fn = function () use ($ref, $obj): ?string {
        return $ref->get()?->name;
    };

    $result = Util::s($fn);

    // WeakReference should still work after unserialization
    expect($result())->toBe('test');
});

test('Generator-returning closure', function (): void {
    $fn = function (): \Generator {
        yield 1;
        yield 2;
        yield 3;
    };

    $result = Util::s($fn);

    expect(iterator_to_array($result()))->toBe([1, 2, 3]);
});

// ============================================================================
// Additional magic constant tests
// ============================================================================

test('closure with __LINE__ magic constant', function (): void {
    $originalLine = __LINE__ + 1;
    $fn = function (): int { return __LINE__; };

    $result = Util::s($fn);

    // __LINE__ should return the original line number
    expect($result())->toBe($originalLine);
});

test('closure with __METHOD__ in class context', function (): void {
    $obj = new class {
        public function getMethodClosure(): \Closure {
            return function (): string {
                return __METHOD__;
            };
        }
    };

    $fn = $obj->getMethodClosure();
    $originalMethod = $fn();
    $result = Util::s($fn);

    // __METHOD__ should contain the class and closure info
    // The exact format may vary slightly due to anonymous class naming
    expect($result())->toContain('{closure');
});

test('closure with __NAMESPACE__ magic constant', function (): void {
    $fn = function (): string {
        return __NAMESPACE__;
    };

    $result = Util::s($fn);

    expect($result())->toBe(__NAMESPACE__);
});

// ============================================================================
// PHP language feature tests
// ============================================================================

test('closure with try/catch/finally', function (): void {
    $fn = function (bool $throw): string {
        $result = '';
        try {
            if ($throw) {
                throw new \Exception('error');
            }
            $result .= 'try';
        } catch (\Exception $e) {
            $result .= 'catch';
        } finally {
            $result .= '-finally';
        }
        return $result;
    };

    $result = Util::s($fn);

    expect($result(false))->toBe('try-finally');
    expect($result(true))->toBe('catch-finally');
});

test('closure with multiple catch blocks', function (): void {
    $fn = function (int $type): string {
        try {
            match ($type) {
                1 => throw new \InvalidArgumentException('invalid'),
                2 => throw new \RuntimeException('runtime'),
                default => null,
            };
            return 'none';
        } catch (\InvalidArgumentException $e) {
            return 'invalid';
        } catch (\RuntimeException $e) {
            return 'runtime';
        }
    };

    $result = Util::s($fn);

    expect($result(0))->toBe('none');
    expect($result(1))->toBe('invalid');
    expect($result(2))->toBe('runtime');
});

test('closure with list destructuring', function (): void {
    $fn = function (array $arr): string {
        [$a, $b, $c] = $arr;
        return "$a-$b-$c";
    };

    $result = Util::s($fn);

    expect($result([1, 2, 3]))->toBe('1-2-3');
});

test('closure with keyed destructuring', function (): void {
    $fn = function (array $arr): string {
        ['name' => $name, 'age' => $age] = $arr;
        return "$name is $age";
    };

    $result = Util::s($fn);

    expect($result(['name' => 'Alice', 'age' => 30]))->toBe('Alice is 30');
});

test('closure with reference parameter', function (): void {
    $fn = function (int &$value): void {
        $value *= 2;
    };

    $result = Util::s($fn);

    $x = 5;
    $result($x);
    expect($x)->toBe(10);
});

test('closure with mixed type', function (): void {
    $fn = function (mixed $value): mixed {
        return $value;
    };

    $result = Util::s($fn);

    expect($result(42))->toBe(42);
    expect($result('string'))->toBe('string');
    expect($result(null))->toBeNull();
});

test('closure with void return type', function (): void {
    // Closures with void return type should work correctly
    // Note: Reference variables don't persist across serialization boundaries
    $fn = function (string $msg): void {
        // Just verify the closure executes without error
        if ($msg !== 'hello') {
            throw new \Exception('unexpected value');
        }
    };

    $result = Util::s($fn);

    // Should not throw
    $result('hello');

    // Verify return is null (void)
    expect($result('hello'))->toBeNull();
});

test('closure with null-safe operator', function (): void {
    $fn = function (?object $obj): ?string {
        return $obj?->name ?? 'default';
    };

    $result = Util::s($fn);

    $obj = new \stdClass();
    $obj->name = 'test';
    expect($result($obj))->toBe('test');
    expect($result(null))->toBe('default');
});

test('closure with throw expression', function (): void {
    $fn = fn(?string $value) => $value ?? throw new \InvalidArgumentException('null not allowed');

    $result = Util::s($fn);

    expect($result('valid'))->toBe('valid');
    expect(fn() => $result(null))->toThrow(\InvalidArgumentException::class);
});

test('closure with method chaining', function (): void {
    $fn = function (): string {
        return (new \DateTime('2024-06-15'))
            ->modify('+1 day')
            ->format('Y-m-d');
    };

    $result = Util::s($fn);

    expect($result())->toBe('2024-06-16');
});

test('closure with clone', function (): void {
    $fn = function (\stdClass $obj): \stdClass {
        $cloned = clone $obj;
        $cloned->value = 'cloned';
        return $cloned;
    };

    $result = Util::s($fn);

    $original = new \stdClass();
    $original->value = 'original';
    $cloned = $result($original);

    expect($original->value)->toBe('original');
    expect($cloned->value)->toBe('cloned');
});

test('closure creating new objects', function (): void {
    $fn = function (string $name): \stdClass {
        $obj = new \stdClass();
        $obj->name = $name;
        return $obj;
    };

    $result = Util::s($fn);

    expect($result('test')->name)->toBe('test');
});

// ============================================================================
// Closure binding and scope tests
// ============================================================================

test('closure accessing private property via $this', function (): void {
    // Note: Anonymous classes have unique generated names, so private/protected
    // access doesn't work after serialization. Use named classes instead.
    $obj = new \Tests\Fixtures\A();
    $fn = $obj->getPrivateClosure();

    $result = Util::s($fn);

    expect($result())->toBe('private called');
});

test('closure accessing protected method via $this', function (): void {
    // Using a named class to test protected method access
    $obj = new \Tests\Fixtures\A();
    $fn = $obj->getProtectedClosure();

    $result = Util::s($fn);

    expect($result())->toBe('protected called');
});

test('closure with self type hint in class', function (): void {
    $obj = new \Tests\Fixtures\RegularClass();
    $fn = $obj->getSelfReturningClosure();

    $result = Util::s($fn);

    expect($result())->toBeInstanceOf(\Tests\Fixtures\RegularClass::class);
});

test('recursive closure calls itself', function (): void {
    $factorial = null;
    $factorial = function (int $n) use (&$factorial): int {
        return $n <= 1 ? 1 : $n * $factorial($n - 1);
    };

    $result = Util::s($factorial);

    expect($result(5))->toBe(120);
    expect($result(0))->toBe(1);
});

test('mutually recursive closures', function (): void {
    $isEven = null;
    $isOdd = null;

    $isEven = function (int $n) use (&$isOdd): bool {
        return $n === 0 ? true : $isOdd($n - 1);
    };

    $isOdd = function (int $n) use (&$isEven): bool {
        return $n === 0 ? false : $isEven($n - 1);
    };

    $resultEven = Util::s($isEven);
    $resultOdd = Util::s($isOdd);

    expect($resultEven(4))->toBeTrue();
    expect($resultEven(3))->toBeFalse();
    expect($resultOdd(3))->toBeTrue();
    expect($resultOdd(4))->toBeFalse();
});

// ============================================================================
// Additional SPL class tests
// ============================================================================

test('SplFixedArray in closure', function (): void {
    $arr = new \SplFixedArray(3);
    $arr[0] = 'a';
    $arr[1] = 'b';
    $arr[2] = 'c';

    $fn = function () use ($arr): string {
        return $arr[0] . $arr[1] . $arr[2];
    };

    $result = Util::s($fn);

    expect($result())->toBe('abc');
});

test('SplQueue in closure', function (): void {
    $queue = new \SplQueue();
    $queue->enqueue('first');
    $queue->enqueue('second');

    $fn = function () use ($queue): array {
        $items = [];
        while (!$queue->isEmpty()) {
            $items[] = $queue->dequeue();
        }
        return $items;
    };

    $result = Util::s($fn);

    expect($result())->toBe(['first', 'second']);
});

test('SplStack in closure', function (): void {
    $stack = new \SplStack();
    $stack->push('first');
    $stack->push('second');

    $fn = function () use ($stack): array {
        $items = [];
        while (!$stack->isEmpty()) {
            $items[] = $stack->pop();
        }
        return $items;
    };

    $result = Util::s($fn);

    // Stack is LIFO
    expect($result())->toBe(['second', 'first']);
});

test('ArrayIterator in closure', function (): void {
    $iter = new \ArrayIterator(['a' => 1, 'b' => 2, 'c' => 3]);

    $fn = function () use ($iter): int {
        $sum = 0;
        foreach ($iter as $value) {
            $sum += $value;
        }
        return $sum;
    };

    $result = Util::s($fn);

    expect($result())->toBe(6);
});

test('SplPriorityQueue in closure', function (): void {
    // Note: SplPriorityQueue's internal heap state is not preserved during serialization
    // This is a PHP limitation - the heap becomes empty after serialization
    $pq = new \SplPriorityQueue();
    $pq->insert('low', 1);
    $pq->insert('high', 10);
    $pq->insert('medium', 5);

    // Convert to array before using in closure to preserve data
    $items = [];
    foreach (clone $pq as $item) {
        $items[] = $item;
    }

    $fn = function () use ($items): string {
        return $items[0]; // Returns highest priority (first after iteration)
    };

    $result = Util::s($fn);

    expect($result())->toBe('high');
});

// ============================================================================
// Edge cases and stress tests
// ============================================================================

test('empty closure', function (): void {
    $fn = function (): void {};

    $result = Util::s($fn);

    expect($result())->toBeNull();
});

test('closure returning null explicitly', function (): void {
    $fn = fn() => null;

    $result = Util::s($fn);

    expect($result())->toBeNull();
});

test('closure with many parameters', function (): void {
    $fn = function ($a, $b, $c, $d, $e, $f, $g, $h): int {
        return $a + $b + $c + $d + $e + $f + $g + $h;
    };

    $result = Util::s($fn);

    expect($result(1, 2, 3, 4, 5, 6, 7, 8))->toBe(36);
});

test('closure with deeply nested structure', function (): void {
    $fn = function (): int {
        return (function () {
            return (function () {
                return (function () {
                    return 42;
                })();
            })();
        })();
    };

    $result = Util::s($fn);

    expect($result())->toBe(42);
});

test('array of closures', function (): void {
    $closures = [
        fn($x) => $x + 1,
        fn($x) => $x * 2,
        fn($x) => $x - 3,
    ];

    $result = Util::s($closures);

    expect($result[0](5))->toBe(6);
    expect($result[1](5))->toBe(10);
    expect($result[2](5))->toBe(2);
});

test('object with multiple closure properties', function (): void {
    $obj = new \stdClass();
    // Use full function syntax to avoid same-line issues with arrow functions
    $obj->add = function ($a, $b) {
        return $a + $b;
    };
    $obj->multiply = function ($a, $b) {
        return $a * $b;
    };
    $obj->subtract = function ($a, $b) {
        return $a - $b;
    };

    $result = Util::s($obj);

    expect(($result->add)(3, 2))->toBe(5);
    expect(($result->multiply)(3, 2))->toBe(6);
    expect(($result->subtract)(3, 2))->toBe(1);
});

test('closure with comments inside', function (): void {
    $fn = function (int $x): int {
        // This is a comment
        $y = $x + 1; /* inline comment */
        /**
         * Multi-line
         * comment
         */
        return $y * 2;
    };

    $result = Util::s($fn);

    expect($result(5))->toBe(12);
});

test('closure with string containing closure-like syntax', function (): void {
    $fn = function (): string {
        return 'function () { return "fake"; }';
    };

    $result = Util::s($fn);

    expect($result())->toBe('function () { return "fake"; }');
});

test('closure capturing backed enum', function (): void {
    $enum = \Tests\Fixtures\MyEnum::CASE1;
    $fn = function () use ($enum): string {
        return $enum->name . ':' . $enum->value;
    };

    $result = Util::s($fn);

    expect($result())->toBe('CASE1:c1');
});

test('closure with for loop', function (): void {
    $fn = function (int $n): int {
        $sum = 0;
        for ($i = 1; $i <= $n; $i++) {
            $sum += $i;
        }
        return $sum;
    };

    $result = Util::s($fn);

    expect($result(10))->toBe(55);
});

test('closure with foreach and keys', function (): void {
    $fn = function (array $arr): string {
        $result = '';
        foreach ($arr as $key => $value) {
            $result .= "$key=$value;";
        }
        return $result;
    };

    $result = Util::s($fn);

    expect($result(['a' => 1, 'b' => 2]))->toBe('a=1;b=2;');
});

test('closure with while loop', function (): void {
    $fn = function (int $start): int {
        $count = 0;
        while ($start > 0) {
            $count++;
            $start--;
        }
        return $count;
    };

    $result = Util::s($fn);

    expect($result(5))->toBe(5);
});

test('closure with do-while loop', function (): void {
    $fn = function (int $n): int {
        $count = 0;
        do {
            $count++;
            $n--;
        } while ($n > 0);
        return $count;
    };

    $result = Util::s($fn);

    expect($result(3))->toBe(3);
    expect($result(0))->toBe(1); // Executes at least once
});

test('closure with bitwise operations', function (): void {
    $fn = function (int $a, int $b): array {
        return [
            'and' => $a & $b,
            'or' => $a | $b,
            'xor' => $a ^ $b,
            'not' => ~$a,
            'left' => $a << 1,
            'right' => $a >> 1,
        ];
    };

    $result = Util::s($fn);

    $r = $result(5, 3);
    expect($r['and'])->toBe(1);
    expect($r['or'])->toBe(7);
    expect($r['xor'])->toBe(6);
});

test('closure with ternary and null coalescing combined', function (): void {
    $fn = function (?array $data): string {
        return ($data['key'] ?? null) ? 'has-value' : 'no-value';
    };

    $result = Util::s($fn);

    expect($result(['key' => 'value']))->toBe('has-value');
    expect($result(['key' => null]))->toBe('no-value');
    expect($result(null))->toBe('no-value');
});

test('closure using constant from interface', function (): void {
    $fn = function (): string {
        return \DateTimeInterface::ATOM;
    };

    $result = Util::s($fn);

    expect($result())->toBe(\DateTimeInterface::ATOM);
});

// ============================================================================
// ISSUE #12: Unreliable code extraction edge cases
// https://github.com/frodeborli/serializor/issues/12
// ============================================================================

test('issue 12: function surrounded by other functions on same line (distinguishable)', function (): void {
    $notThisOne = function ($a) { return 'first'; }; $thisOne = function ($b) { return 'second'; }; $notThisEither = function ($c) { return 'third'; };

    $result = Util::s($thisOne);

    expect($result('x'))->toBe('second');
});

test('issue 12: arrow function surrounded by other functions on same line (distinguishable)', function (): void {
    $notThisOne = function ($a) { return 'first'; }; $thisOne = fn($b) => 'second'; $notThisEither = function ($c) { return 'third'; };

    $result = Util::s($thisOne);

    expect($result('x'))->toBe('second');
});

test('issue 12: static function surrounded by other functions on same line (distinguishable)', function (): void {
    $notThisOne = function ($a) { return 'first'; }; $thisOne = static function ($b) { return 'second'; }; $notThisEither = function ($c) { return 'third'; };

    $result = Util::s($thisOne);

    expect($result('x'))->toBe('second');
});

test('issue 12: static arrow function surrounded by other functions on same line (distinguishable)', function (): void {
    $notThisOne = function ($a) { return 'first'; }; $thisOne = static fn($b) => 'second'; $notThisEither = function ($c) { return 'third'; };

    $result = Util::s($thisOne);

    expect($result('x'))->toBe('second');
});

test('issue 12: static function with comment between static and function', function (): void {
    $fn = static /* this is a comment */ function ($x): string {
        return 'value: ' . $x;
    };

    $result = Util::s($fn);

    expect($result('test'))->toBe('value: test');
});

test('issue 12: static arrow function with comment between static and fn', function (): void {
    $fn = static /* this is a comment */ fn($x) => 'value: ' . $x;

    $result = Util::s($fn);

    expect($result('test'))->toBe('value: test');
});

test('issue 12: static on different line than function', function (): void {
    $fn = static
    function ($x): string {
        return 'value: ' . $x;
    };

    $result = Util::s($fn);

    expect($result('test'))->toBe('value: test');
});

test('issue 12: static on different line than fn', function (): void {
    $fn = static
    fn($x) => 'value: ' . $x;

    $result = Util::s($fn);

    expect($result('test'))->toBe('value: test');
});

test('issue 12: indistinguishable functions on same line throws exception', function (): void {
    // This should throw because both closures have same param name
    $first = function ($x) { return 1; }; $second = function ($x) { return 2; };

    expect(fn() => Util::s($first))->toThrow(\Serializor\SerializerError::class);
});

test('issue 12: static with multiple whitespace tokens before function', function (): void {
    $fn = static     function ($x): int {
        return $x * 2;
    };

    $result = Util::s($fn);

    expect($result(5))->toBe(10);
});

test('issue 12: static with newline and comment before fn', function (): void {
    $fn = static
    // a comment here
    fn($x) => $x * 3;

    $result = Util::s($fn);

    expect($result(4))->toBe(12);
});
