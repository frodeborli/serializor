<?php

declare(strict_types=1);

namespace Tests;

use ArrayObject;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use DateInterval;
use DatePeriod;
use SplDoublyLinkedList;
use SplFixedArray;
use SplHeap;
use SplMaxHeap;
use SplMinHeap;
use SplObjectStorage;
use SplPriorityQueue;
use SplQueue;
use SplStack;
use stdClass;
use Tests\Fixtures\Util;
use WeakMap;
use WeakReference;

// ============================================================================
// SPL COLLECTION CLASSES - WITHOUT CLOSURES
// ============================================================================

test('ArrayObject without closures', function () {
    $arr = new ArrayObject(['a', 'b', 'c']);
    $restored = Util::s($arr);

    expect($restored)->toBeInstanceOf(ArrayObject::class);
    expect(count($restored))->toBe(3);
    expect($restored[0])->toBe('a');
});

test('SplFixedArray without closures', function () {
    $arr = SplFixedArray::fromArray([1, 2, 3]);
    $restored = Util::s($arr);

    expect($restored)->toBeInstanceOf(SplFixedArray::class);
    expect(count($restored))->toBe(3);
    expect($restored[0])->toBe(1);
});

test('SplDoublyLinkedList without closures', function () {
    $list = new SplDoublyLinkedList();
    $list->push('a');
    $list->push('b');
    $restored = Util::s($list);

    expect($restored)->toBeInstanceOf(SplDoublyLinkedList::class);
    expect(count($restored))->toBe(2);
    expect($restored[0])->toBe('a');
});

test('SplStack without closures', function () {
    $stack = new SplStack();
    $stack->push('a');
    $stack->push('b');
    $restored = Util::s($stack);

    expect($restored)->toBeInstanceOf(SplStack::class);
    expect(count($restored))->toBe(2);
});

test('SplQueue without closures', function () {
    $queue = new SplQueue();
    $queue->enqueue('a');
    $queue->enqueue('b');
    $restored = Util::s($queue);

    expect($restored)->toBeInstanceOf(SplQueue::class);
    expect(count($restored))->toBe(2);
});

test('SplPriorityQueue without closures', function () {
    $pq = new SplPriorityQueue();
    $pq->insert('low', 1);
    $pq->insert('high', 10);
    $restored = Util::s($pq);

    expect($restored)->toBeInstanceOf(SplPriorityQueue::class);
    expect(count($restored))->toBe(2);
});

test('SplMaxHeap without closures', function () {
    $heap = new SplMaxHeap();
    $heap->insert(1);
    $heap->insert(5);
    $heap->insert(3);
    $restored = Util::s($heap);

    expect($restored)->toBeInstanceOf(SplMaxHeap::class);
    expect(count($restored))->toBe(3);
    expect($restored->top())->toBe(5);
});

test('SplMinHeap without closures', function () {
    $heap = new SplMinHeap();
    $heap->insert(5);
    $heap->insert(1);
    $heap->insert(3);
    $restored = Util::s($heap);

    expect($restored)->toBeInstanceOf(SplMinHeap::class);
    expect(count($restored))->toBe(3);
    expect($restored->top())->toBe(1);
});

// ============================================================================
// DATE/TIME CLASSES
// ============================================================================

test('DateTime serialization', function () {
    $dt = new DateTime('2024-06-15 14:30:00', new DateTimeZone('UTC'));
    $restored = Util::s($dt);

    expect($restored)->toBeInstanceOf(DateTime::class);
    expect($restored->format('Y-m-d H:i:s'))->toBe('2024-06-15 14:30:00');
});

test('DateTimeImmutable serialization', function () {
    $dt = new DateTimeImmutable('2024-06-15 14:30:00');
    $restored = Util::s($dt);

    expect($restored)->toBeInstanceOf(DateTimeImmutable::class);
    expect($restored->format('Y-m-d H:i:s'))->toBe('2024-06-15 14:30:00');
});

test('DateTimeZone serialization', function () {
    $tz = new DateTimeZone('Europe/Oslo');
    $restored = Util::s($tz);

    expect($restored)->toBeInstanceOf(DateTimeZone::class);
    expect($restored->getName())->toBe('Europe/Oslo');
});

test('DateInterval serialization', function () {
    $interval = new DateInterval('P1Y2M3D');
    $restored = Util::s($interval);

    expect($restored)->toBeInstanceOf(DateInterval::class);
    expect($restored->y)->toBe(1);
    expect($restored->m)->toBe(2);
    expect($restored->d)->toBe(3);
});

test('DatePeriod serialization', function () {
    $start = new DateTime('2024-01-01');
    $interval = new DateInterval('P1M');
    $period = new DatePeriod($start, $interval, 3);
    $restored = Util::s($period);

    expect($restored)->toBeInstanceOf(DatePeriod::class);
    expect(iterator_count($restored))->toBe(4); // start + 3 recurrences
});

// ============================================================================
// SPL COLLECTION CLASSES - WITH CLOSURES
// ============================================================================

test('ArrayObject with closure', function () {
    $multiplier = 3;
    $arr = new ArrayObject([
        'name' => 'test',
        'fn' => fn($x) => str_repeat($x, $multiplier),
    ]);
    $restored = Util::s($arr);

    expect($restored)->toBeInstanceOf(ArrayObject::class);
    expect($restored['name'])->toBe('test');
    expect($restored['fn']('X'))->toBe('XXX');
});

test('SplDoublyLinkedList with closure', function () {
    $value = 42;
    $list = new SplDoublyLinkedList();
    $list->push(fn() => $value);
    $list->push('plain');
    $restored = Util::s($list);

    expect($restored)->toBeInstanceOf(SplDoublyLinkedList::class);
    expect($restored[0]())->toBe(42);
    expect($restored[1])->toBe('plain');
});

test('SplStack with closure', function () {
    $stack = new SplStack();
    $stack->push(fn() => 'from closure');
    $restored = Util::s($stack);

    expect($restored)->toBeInstanceOf(SplStack::class);
    expect($restored->top()())->toBe('from closure');
});

test('SplQueue with closure', function () {
    $queue = new SplQueue();
    $queue->enqueue(fn() => 'queued');
    $restored = Util::s($queue);

    expect($restored)->toBeInstanceOf(SplQueue::class);
    expect($restored->dequeue()())->toBe('queued');
});

test('SplFixedArray with closure', function () {
    $arr = new SplFixedArray(2);
    $arr[0] = fn() => 'first';
    $arr[1] = 'second';
    $restored = Util::s($arr);

    expect($restored)->toBeInstanceOf(SplFixedArray::class);
    expect($restored[0]())->toBe('first');
    expect($restored[1])->toBe('second');
});

// ============================================================================
// ALREADY SUPPORTED TYPES (VERIFICATION)
// ============================================================================

test('stdClass with closure property', function () {
    $obj = new stdClass();
    $obj->fn = fn() => 'works';
    $restored = Util::s($obj);

    expect(($restored->fn)())->toBe('works');
});

test('SplObjectStorage with closure as data', function () {
    $storage = new SplObjectStorage();
    $key = new stdClass();
    $storage[$key] = fn() => 'stored';

    $data = ['storage' => $storage, 'key' => $key];
    $restored = Util::s($data);

    expect($restored['storage'][$restored['key']]())->toBe('stored');
});

test('WeakMap with closure as value', function () {
    $map = new WeakMap();
    $key = new stdClass();
    $map[$key] = fn() => 'weak value';

    $data = ['map' => $map, 'key' => $key];
    $restored = Util::s($data);

    expect($restored['map'][$restored['key']]())->toBe('weak value');
});

// ============================================================================
// NESTED STRUCTURES
// ============================================================================

test('ArrayObject containing SplDoublyLinkedList with closures', function () {
    $list = new SplDoublyLinkedList();
    $list->push(fn() => 'nested');

    $arr = new ArrayObject(['list' => $list]);
    $restored = Util::s($arr);

    expect($restored['list'][0]())->toBe('nested');
});

test('DateTime in closure use variable', function () {
    $date = new DateTime('2024-01-01');
    $fn = fn() => $date->format('Y');
    $restored = Util::s($fn);

    expect($restored())->toBe('2024');
});
