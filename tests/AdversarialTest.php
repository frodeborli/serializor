<?php

declare(strict_types=1);

namespace Tests;

use Serializor;
use Serializor\Codec;
use Serializor\SerializerError;
use stdClass;
use WeakMap;
use WeakReference;

/**
 * Adversarial test suite covering security, edge cases, and correctness.
 *
 * These tests target potential vulnerabilities and corner cases that could
 * cause incorrect behavior or security issues.
 */

// ============================================================================
// SECURITY TESTS (8 tests)
// ============================================================================

test('partial tampering of payload is rejected', function () {
    $codec = new Codec('test-secret');
    $closure = fn() => 'original';
    $serialized = $codec->serialize($closure);

    // Tamper with the payload portion (after the | separator)
    $parts = explode('|', $serialized, 2);
    $parts[1] = str_replace('original', 'tampered', $parts[1]);
    $tampered = implode('|', $parts);

    expect(fn() => $codec->unserialize($tampered))->toThrow(SerializerError::class);
});

test('partial tampering of signature is rejected', function () {
    $codec = new Codec('test-secret');
    $closure = fn() => 'test';
    $serialized = $codec->serialize($closure);

    // Corrupt the signature more aggressively (replace all hex chars)
    $parts = explode('|', $serialized, 2);
    $corruptedSig = str_repeat('0', strlen($parts[0]));
    $tampered = $corruptedSig . '|' . $parts[1];

    expect(fn() => $codec->unserialize($tampered))->toThrow(SerializerError::class);
});

test('missing separator is rejected', function () {
    $codec = new Codec('test-secret');
    $closure = fn() => 'test';
    $serialized = $codec->serialize($closure);

    // Remove the separator
    $tampered = str_replace('|', '', $serialized);

    expect(fn() => $codec->unserialize($tampered))->toThrow(SerializerError::class);
});

test('wrong secret is rejected', function () {
    $codec1 = new Codec('secret-one');
    $codec2 = new Codec('secret-two');

    $closure = fn() => 'test';
    $serialized = $codec1->serialize($closure);

    expect(fn() => $codec2->unserialize($serialized))->toThrow(SerializerError::class);
});

test('truncated serialized data is rejected', function () {
    $codec = new Codec('test-secret');
    $closure = fn() => 'test';
    $serialized = $codec->serialize($closure);

    // Truncate at various points - will fail signature validation
    $truncated = substr($serialized, 0, strlen($serialized) - 10);
    expect(fn() => $codec->unserialize($truncated))->toThrow(SerializerError::class);

    $truncated = substr($serialized, 0, 64); // Just signature
    expect(fn() => $codec->unserialize($truncated))->toThrow(SerializerError::class);
});

test('junk suffix appended to data is rejected', function () {
    $codec = new Codec('test-secret');
    $closure = fn() => 'test';
    $serialized = $codec->serialize($closure);

    // Append junk data
    $tampered = $serialized . 'junk_suffix';

    expect(fn() => $codec->unserialize($tampered))->toThrow(SerializerError::class);
});

test('secret stability - same secret produces verifiable data', function () {
    $secret = 'stable-secret-key';
    $codec1 = new Codec($secret);
    $codec2 = new Codec($secret);

    $closure = fn() => 'data';
    $serialized = $codec1->serialize($closure);

    // Should be able to unserialize with a new codec instance using same secret
    $restored = $codec2->unserialize($serialized);
    expect($restored())->toBe('data');
});

test('empty secret allows unsigned serialization', function () {
    $codec = new Codec('');
    $closure = fn() => 'test';
    $serialized = $codec->serialize($closure);

    // Should not contain signature separator for empty secret
    expect($serialized)->not->toContain('|');

    $restored = $codec->unserialize($serialized);
    expect($restored())->toBe('test');
});

// ============================================================================
// SOURCE EXTRACTION EDGE CASES (5 tests)
// ============================================================================

test('two identical closures on same line throws exception', function () {
    // Multiple closures on the same line with identical signatures cannot be distinguished.
    // Serializor throws an exception rather than silently picking the wrong one.
    $a = fn() => 'first'; $b = fn() => 'second';

    $codec = new Codec('secret');

    // Both have identical signatures: fn() with no parameters
    // Should throw because they can't be distinguished
    expect(fn() => $codec->serialize($a))->toThrow(SerializerError::class, 'multiple closures');
});

test('two distinguishable closures on same line work correctly', function () {
    // Closures can be distinguished by their parameter names
    $a = fn($x) => $x + 1; $b = fn($y) => $y * 2;

    $codec = new Codec('secret');
    $serializedA = $codec->serialize($a);
    $serializedB = $codec->serialize($b);

    $restoredA = $codec->unserialize($serializedA);
    $restoredB = $codec->unserialize($serializedB);

    expect($restoredA(5))->toBe(6);
    expect($restoredB(5))->toBe(10);
});

test('closures with different use vars on same line work correctly', function () {
    $val1 = 10;
    $val2 = 20;
    $a = function () use ($val1) { return $val1; }; $b = function () use ($val2) { return $val2; };

    $codec = new Codec('secret');
    $serializedA = $codec->serialize($a);
    $serializedB = $codec->serialize($b);

    $restoredA = $codec->unserialize($serializedA);
    $restoredB = $codec->unserialize($serializedB);

    expect($restoredA())->toBe(10);
    expect($restoredB())->toBe(20);
});

test('closure with embedded PHP tags in string literal', function () {
    $closure = fn() => '<?php echo "fake"; ?>';

    $codec = new Codec('secret');
    $serialized = $codec->serialize($closure);
    $restored = $codec->unserialize($serialized);

    expect($restored())->toBe('<?php echo "fake"; ?>');
});

test('closure with malformed syntax in string literal', function () {
    $closure = fn() => 'function { broken syntax } class extends';

    $codec = new Codec('secret');
    $serialized = $codec->serialize($closure);
    $restored = $codec->unserialize($serialized);

    expect($restored())->toBe('function { broken syntax } class extends');
});

test('heredoc containing function keyword', function () {
    $closure = function () {
        return <<<'EOT'
function notARealFunction() {
    return "This is just a string";
}
EOT;
    };

    $codec = new Codec('secret');
    $serialized = $codec->serialize($closure);
    $restored = $codec->unserialize($serialized);

    expect($restored())->toContain('function notARealFunction');
});

test('closure with regex containing closure-like pattern', function () {
    $closure = fn($str) => preg_match('/function\s*\([^)]*\)\s*{/', $str);

    $codec = new Codec('secret');
    $serialized = $codec->serialize($closure);
    $restored = $codec->unserialize($serialized);

    expect($restored('function () { }'))->toBe(1);
    expect($restored('no match'))->toBe(0);
});

// ============================================================================
// BY-REFERENCE SEMANTICS (4 tests)
// ============================================================================

test('reference cell mutation persists in restored closure', function () {
    $counter = 0;
    $increment = function () use (&$counter) {
        return ++$counter;
    };

    $codec = new Codec('secret');
    $serialized = $codec->serialize($increment);
    $restored = $codec->unserialize($serialized);

    // Restored closure has its own counter starting at 0
    expect($restored())->toBe(1);
    expect($restored())->toBe(2);

    // Original counter is unaffected
    expect($counter)->toBe(0);
});

test('$this capture in bound closure with named class', function () {
    // Note: Anonymous classes can't preserve private property access because
    // their scope class names are unique and can't be recreated.
    // Use Tests\Fixtures\A which has getPrivateClosure() method.
    $obj = new \Tests\Fixtures\A();
    $closure = $obj->getPrivateClosure();

    $codec = new Codec('secret');
    $serialized = $codec->serialize($closure);
    $restored = $codec->unserialize($serialized);

    // The closure should access the private property through proper scoping
    expect($restored())->toBe('private called');
});

test('static closure does not capture $this', function () {
    $obj = new class {
        private string $value = 'test';
        public function getStaticClosure(): \Closure {
            return static function () {
                return 'static closure';
            };
        }
    };

    $closure = $obj->getStaticClosure();
    $codec = new Codec('secret');
    $serialized = $codec->serialize($closure);
    $restored = $codec->unserialize($serialized);

    expect($restored())->toBe('static closure');
});

test('closure with by-reference use variable', function () {
    $arr = [1, 2, 3];
    $push = function ($v) use (&$arr) {
        $arr[] = $v;
        return $arr;
    };

    $codec = new Codec('secret');
    $serialized = $codec->serialize($push);
    $restored = $codec->unserialize($serialized);

    // Restored closure has its own copy of the array
    $result = $restored(4);
    expect($result)->toBe([1, 2, 3, 4]);

    // Original array is unaffected
    expect($arr)->toBe([1, 2, 3]);
});

// ============================================================================
// OBJECT IDENTITY (4 tests)
// ============================================================================

test('same object at 3+ paths remains identical', function () {
    $shared = new stdClass();
    $shared->id = 'shared';

    $data = [
        'first' => $shared,
        'second' => $shared,
        'nested' => ['deep' => $shared],
    ];

    $codec = new Codec('secret');
    $serialized = $codec->serialize($data);
    $restored = $codec->unserialize($serialized);

    expect($restored['first'])->toBe($restored['second']);
    expect($restored['first'])->toBe($restored['nested']['deep']);
    expect($restored['first']->id)->toBe('shared');
});

test('SplObjectStorage preserves object identity', function () {
    $obj1 = new stdClass();
    $obj1->id = 1;
    $obj2 = new stdClass();
    $obj2->id = 2;

    $storage = new \SplObjectStorage();
    $storage[$obj1] = 'data1';
    $storage[$obj2] = 'data2';

    $data = [
        'storage' => $storage,
        'ref1' => $obj1,
        'ref2' => $obj2,
    ];

    $codec = new Codec('secret');
    $serialized = $codec->serialize($data);
    $restored = $codec->unserialize($serialized);

    // The objects in storage should be the same instances as ref1/ref2
    expect($restored['storage'][$restored['ref1']])->toBe('data1');
    expect($restored['storage'][$restored['ref2']])->toBe('data2');
});

test('WeakReference is dead when object not strongly referenced elsewhere', function () {
    $obj = new stdClass();
    $obj->value = 'test';

    $data = [
        'weak' => WeakReference::create($obj),
        // Note: $obj is NOT included elsewhere in $data
    ];

    $codec = new Codec('secret');
    $serialized = $codec->serialize($data);

    // Clear the original reference
    unset($obj);

    $restored = $codec->unserialize($serialized);

    // WeakReference should be dead because the object wasn't strongly referenced
    expect($restored['weak']->get())->toBeNull();
});

test('WeakReference is alive when object strongly referenced elsewhere', function () {
    $obj = new stdClass();
    $obj->value = 'test';

    $data = [
        'weak' => WeakReference::create($obj),
        'strong' => $obj, // Strong reference keeps the object alive
    ];

    $codec = new Codec('secret');
    $serialized = $codec->serialize($data);
    $restored = $codec->unserialize($serialized);

    // WeakReference should be alive and point to the same object
    expect($restored['weak']->get())->toBe($restored['strong']);
    expect($restored['weak']->get()->value)->toBe('test');
});

// ============================================================================
// WEAKMAP TESTS (4 tests)
// ============================================================================

test('WeakMap basic serialization', function () {
    $key1 = new stdClass();
    $key1->id = 'key1';
    $key2 = new stdClass();
    $key2->id = 'key2';

    $map = new WeakMap();
    $map[$key1] = 'value1';
    $map[$key2] = 'value2';

    $data = [
        'map' => $map,
        'k1' => $key1,
        'k2' => $key2,
    ];

    $codec = new Codec('secret');
    $serialized = $codec->serialize($data);
    $restored = $codec->unserialize($serialized);

    expect($restored['map'][$restored['k1']])->toBe('value1');
    expect($restored['map'][$restored['k2']])->toBe('value2');
});

test('WeakMap entry is dead when key not strongly referenced', function () {
    $key = new stdClass();
    $key->id = 'ephemeral';

    $map = new WeakMap();
    $map[$key] = 'data';

    $data = [
        'map' => $map,
        // Note: $key is NOT included elsewhere
    ];

    $codec = new Codec('secret');
    $serialized = $codec->serialize($data);

    unset($key);

    $restored = $codec->unserialize($serialized);

    // WeakMap should have no entries because the key wasn't strongly referenced
    expect(count($restored['map']))->toBe(0);
});

test('WeakMap preserves entries with strongly referenced keys', function () {
    $key1 = new stdClass();
    $key1->id = 'strong';
    $key2 = new stdClass();
    $key2->id = 'weak';

    $map = new WeakMap();
    $map[$key1] = 'preserved';
    $map[$key2] = 'lost';

    $data = [
        'map' => $map,
        'strongKey' => $key1, // Only key1 is strongly referenced
    ];

    $codec = new Codec('secret');
    $serialized = $codec->serialize($data);
    $restored = $codec->unserialize($serialized);

    // Only the entry with the strongly referenced key should exist
    expect(count($restored['map']))->toBe(1);
    expect($restored['map'][$restored['strongKey']])->toBe('preserved');
});

test('WeakMap values are strongly referenced', function () {
    $key = new stdClass();
    $key->id = 'key';
    $value = new stdClass();
    $value->id = 'value';

    $map = new WeakMap();
    $map[$key] = $value;

    $data = [
        'map' => $map,
        'key' => $key,
        'valueRef' => WeakReference::create($value),
    ];

    $codec = new Codec('secret');
    $serialized = $codec->serialize($data);
    $restored = $codec->unserialize($serialized);

    // The value should be preserved because WeakMap values are strongly held
    expect($restored['valueRef']->get())->not->toBeNull();
    expect($restored['valueRef']->get())->toBe($restored['map'][$restored['key']]);
});

// ============================================================================
// DESERIALIZATION SAFETY (4 tests)
// ============================================================================

test('circular reference handling', function () {
    $a = new stdClass();
    $b = new stdClass();
    $a->ref = $b;
    $b->ref = $a;
    $a->id = 'a';
    $b->id = 'b';

    $codec = new Codec('secret');
    $serialized = $codec->serialize($a);
    $restored = $codec->unserialize($serialized);

    expect($restored->id)->toBe('a');
    expect($restored->ref->id)->toBe('b');
    expect($restored->ref->ref)->toBe($restored);
});

test('deep nesting (100+ levels)', function () {
    $depth = 150;
    $root = new stdClass();
    $current = $root;
    for ($i = 0; $i < $depth; $i++) {
        $current->child = new stdClass();
        $current->level = $i;
        $current = $current->child;
    }
    $current->level = $depth;

    $codec = new Codec('secret');
    $serialized = $codec->serialize($root);
    $restored = $codec->unserialize($serialized);

    // Verify structure
    $current = $restored;
    for ($i = 0; $i < $depth; $i++) {
        expect($current->level)->toBe($i);
        $current = $current->child;
    }
    expect($current->level)->toBe($depth);
});

test('large sibling count (1000+ objects)', function () {
    $count = 1000;
    $siblings = [];
    for ($i = 0; $i < $count; $i++) {
        $obj = new stdClass();
        $obj->index = $i;
        $siblings[] = $obj;
    }

    $codec = new Codec('secret');
    $serialized = $codec->serialize($siblings);
    $restored = $codec->unserialize($serialized);

    expect(count($restored))->toBe($count);
    expect($restored[0]->index)->toBe(0);
    expect($restored[$count - 1]->index)->toBe($count - 1);
});

test('mixed weak/strong reference graph', function () {
    $shared = new stdClass();
    $shared->id = 'shared';

    $strongOnly = new stdClass();
    $strongOnly->id = 'strong-only';

    $weakOnly = new stdClass();
    $weakOnly->id = 'weak-only';

    $data = [
        'shared_strong' => $shared,
        'shared_weak' => WeakReference::create($shared),
        'strong_only' => $strongOnly,
        'weak_only' => WeakReference::create($weakOnly),
    ];

    $codec = new Codec('secret');
    $serialized = $codec->serialize($data);
    $restored = $codec->unserialize($serialized);

    // shared should be preserved in both strong and weak references
    expect($restored['shared_strong']->id)->toBe('shared');
    expect($restored['shared_weak']->get())->toBe($restored['shared_strong']);

    // strong_only should be preserved
    expect($restored['strong_only']->id)->toBe('strong-only');

    // weak_only should be dead (no strong reference)
    expect($restored['weak_only']->get())->toBeNull();
});

test('closure capturing self-referencing array with nested closure', function () {
    $array = [];
    $array[] = &$array;
    $array[] = function () use (&$array) {
        return count($array);
    };
    $closure = fn() => $array[1]();

    $codec = new Codec('secret');
    $serialized = $codec->serialize($closure);
    $restored = $codec->unserialize($serialized);

    // The restored closure should work correctly
    expect($restored())->toBe(2);
});
