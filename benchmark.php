<?php

require 'vendor/autoload.php'; // Adjust this to load Opis/Closure and Serializor

use Opis\Closure\SerializableClosure;

// Define iteration count per test case if needed
$defaultIterations = 1000;
$iterations = [
    'simple_closure' => 1000,
    'closure_with_use' => 1000,
    'complex_closure' => 1000,
    'closure_with_object' => 500,
    'complex_recursive_structure' => 500,
];

$results = [
    'opis' => [],
    'serializor' => [],
];

// Benchmark function with memory tracking
function benchmark(callable $serializeFn, callable $unserializeFn, $closure, $iterations)
{
    // Measure serialize time and memory usage
    $serializeStart = microtime(true);
    $memoryBeforeSerialize = memory_get_usage();

    for ($i = 0; $i < $iterations; $i++) {
        $serialized = $serializeFn($closure);
    }

    $memoryAfterSerialize = memory_get_usage();
    $serializeEnd = microtime(true);
    $serializeTime = $serializeEnd - $serializeStart;
    $serializeMemory = $memoryAfterSerialize - $memoryBeforeSerialize;

    // Measure unserialize time and memory usage
    $unserializeStart = microtime(true);
    $memoryBeforeUnserialize = memory_get_usage();

    for ($i = 0; $i < $iterations; $i++) {
        $unserialized = $unserializeFn($serialized);
    }

    $memoryAfterUnserialize = memory_get_usage();
    $unserializeEnd = microtime(true);
    $unserializeTime = $unserializeEnd - $unserializeStart;
    $unserializeMemory = $memoryAfterUnserialize - $memoryBeforeUnserialize;

    return [
        'serialize_time' => $serializeTime,
        'unserialize_time' => $unserializeTime,
        'serialize_memory' => $serializeMemory,
        'unserialize_memory' => $unserializeMemory,
    ];
}

// Test cases
$testCases = [
    'simple_closure' => function () {
        return function () {
            return 'Simple closure';
        };
    },
    'closure_with_use' => function () {
        $value = 42;
        return function () use ($value) {
            return $value;
        };
    },
    'complex_closure' => function () {
        $a = 10;
        $b = 20;
        return function () use ($a, $b) {
            return $a + $b;
        };
    },
    'closure_with_object' => function () {
        $object = (object) [
            'property' => 'Hello'
        ];
        return function () use ($object) {
            return $object->property;
        };
    },
    'complex_recursive_structure' => function () {
        $array = [];
        $array[] = &$array;
        $array[] = function () use (&$array) {
            return count($array);
        };
        return function () use ($array) {
            return $array[0][2]();
        };
    },
    'closure_with_nested_closures' => function () {
        $nestedClosure = function ($x) {
            return function ($y) use ($x) {
                return $x + $y;
            };
        };
        return function () use ($nestedClosure) {
            return $nestedClosure(10)(20);
        };
    }
];

// Run benchmarks for each test case
foreach ($testCases as $caseName => $testClosureFactory) {
    // Get the number of iterations for this test case
    $numIterations = $iterations[$caseName] ?? $defaultIterations;

    // Generate the closure
    $testClosure = $testClosureFactory();

    echo "Serializor ($caseName)...\n";
    // Serializor benchmark
    try {
        $serializorResults = benchmark(
            fn($closure) => Serializor::serialize($closure),
            fn($serialized) => Serializor::unserialize($serialized),
            $testClosure,
            $numIterations
        );

        // Store results for Serializor
        $results['serializor'][$caseName] = [
            'serialize_time' => $serializorResults['serialize_time'],
            'unserialize_time' => $serializorResults['unserialize_time'],
            'serialize_memory' => $serializorResults['serialize_memory'],
            'unserialize_memory' => $serializorResults['unserialize_memory'],
        ];
    } catch (Throwable $e) {
        echo "Error in Serializor ($caseName): " . $e->getMessage() . "\n";
    }

    // Skip certain cases for Opis/Closure (e.g., recursive structures)
    if ($caseName === 'complex_recursive_structure') {
        echo "Skipping complex_recursive_structure for Opis...\n";
        continue;
    }

    echo "OPIS ($caseName)...\n";
    // Opis/Closure benchmark
    try {
        $opisResults = benchmark(
            fn($closure) => serialize(new SerializableClosure($closure)),
            fn($serialized) => unserialize($serialized)->getClosure(),
            $testClosure,
            $numIterations
        );

        // Store results for Opis
        $results['opis'][$caseName] = [
            'serialize_time' => $opisResults['serialize_time'],
            'unserialize_time' => $opisResults['unserialize_time'],
            'serialize_memory' => $opisResults['serialize_memory'],
            'unserialize_memory' => $opisResults['unserialize_memory'],
        ];
    } catch (Throwable $e) {
        echo "Error in Opis ($caseName): " . $e->getMessage() . "\n";
    }
}

// Write results to JSON file
file_put_contents('benchmark_results.json', json_encode($results, JSON_PRETTY_PRINT));

echo "Benchmark complete. Results saved to benchmark_results.json\n";
