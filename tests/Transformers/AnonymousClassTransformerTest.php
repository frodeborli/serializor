<?php

declare(strict_types=1);

namespace Tests\Transformers;

use Tests\Fixtures\Util;

// ============================================================================
// ANONYMOUS CLASS TRANSFORMER TESTS
// Issue #10: Can't serialize anonymous class implementing interface
// ============================================================================

test('plain anonymous class', function (): void {
    $obj = new class {
        public string $value = 'test';

        public function getValue(): string
        {
            return $this->value;
        }
    };

    $result = Util::s($obj);

    expect($result->getValue())->toBe('test');
});

test('issue 10: anonymous class implementing interface', function (): void {
    $obj = new class implements \Stringable {
        private string $value = 'interface-value';

        public function __toString(): string
        {
            return $this->value;
        }
    };

    $result = Util::s($obj);

    expect($result)->toBeInstanceOf(\Stringable::class);
    expect((string) $result)->toBe('interface-value');
});

// Note: Anonymous classes extending internal PHP classes (stdClass, ArrayObject, etc.)
// cannot be serialized due to PHP's restriction on binding closures to internal class scopes.
// This is a known limitation.

test('anonymous class with constructor', function (): void {
    $obj = new class('constructed') {
        private string $value;

        public function __construct(string $value)
        {
            $this->value = $value;
        }

        public function getValue(): string
        {
            return $this->value;
        }
    };

    $result = Util::s($obj);

    expect($result->getValue())->toBe('constructed');
});

test('anonymous class with promoted constructor properties', function (): void {
    $obj = new class('promoted-value', 42) {
        public function __construct(
            private string $value,
            public int $number
        ) {}

        public function getValue(): string
        {
            return $this->value;
        }
    };

    $result = Util::s($obj);

    expect($result->getValue())->toBe('promoted-value');
    expect($result->number)->toBe(42);
});

test('anonymous class implementing multiple interfaces', function (): void {
    $obj = new class implements \Stringable, \Countable
    {
        private array $items = ['a', 'b', 'c'];

        public function __toString(): string
        {
            return implode(',', $this->items);
        }

        public function count(): int
        {
            return count($this->items);
        }
    };

    $result = Util::s($obj);

    expect($result)->toBeInstanceOf(\Stringable::class);
    expect($result)->toBeInstanceOf(\Countable::class);
    expect((string) $result)->toBe('a,b,c');
    expect(count($result))->toBe(3);
});

test('nested anonymous class', function (): void {
    $outer = new class {
        public function createInner(): object
        {
            return new class {
                public string $innerValue = 'inner';
            };
        }
    };

    $inner = $outer->createInner();

    $result = Util::s($inner);

    expect($result->innerValue)->toBe('inner');
});

test('anonymous class with static property', function (): void {
    $obj = new class {
        public static int $counter = 0;
        public int $id;

        public function __construct()
        {
            $this->id = ++self::$counter;
        }
    };

    $result = Util::s($obj);

    expect($result->id)->toBe($obj->id);
});

test('anonymous class with readonly property', function (): void {
    $obj = new class('readonly-value') {
        public function __construct(
            public readonly string $value
        ) {}
    };

    $result = Util::s($obj);

    expect($result->value)->toBe('readonly-value');
});

// ============================================================================
// SAME-LINE ANONYMOUS CLASS DISAMBIGUATION
// ============================================================================

test('same-line anonymous classes with different implements can be disambiguated', function (): void {
    $first = new class implements \Stringable { public function __toString(): string { return 'first'; } }; $second = new class implements \Countable { public function count(): int { return 42; } };

    $resultFirst = Util::s($first);
    $resultSecond = Util::s($second);

    expect($resultFirst)->toBeInstanceOf(\Stringable::class);
    expect((string) $resultFirst)->toBe('first');
    expect($resultSecond)->toBeInstanceOf(\Countable::class);
    expect(count($resultSecond))->toBe(42);
});

test('same-line anonymous classes with different extends can be disambiguated', function (): void {
    $first = new class extends \Tests\Fixtures\A { public function getValue(): string { return 'from-A'; } }; $second = new class extends \Tests\Fixtures\RegularClass { public function getValue(): string { return 'from-Regular'; } };

    $resultFirst = Util::s($first);
    $resultSecond = Util::s($second);

    expect($resultFirst)->toBeInstanceOf(\Tests\Fixtures\A::class);
    expect($resultFirst->getValue())->toBe('from-A');
    expect($resultSecond)->toBeInstanceOf(\Tests\Fixtures\RegularClass::class);
    expect($resultSecond->getValue())->toBe('from-Regular');
});

test('same-line anonymous classes that cannot be disambiguated throws error', function (): void {
    // Two identical anonymous classes on same line - cannot disambiguate
    $first = new class { public string $v = 'a'; }; $second = new class { public string $v = 'b'; };

    // Should throw for the second one (first one matches first class found)
    Util::s($second);
})->throws(\Serializor\SerializerError::class, 'cannot be disambiguated');
