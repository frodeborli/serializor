<?php
/**
 * Benchmark: Closure Serialization Libraries
 *
 * Compares performance of three PHP closure serialization libraries:
 * - Serializor (fubber/serializor)
 * - Opis Closure (opis/closure)
 * - Laravel Serializable Closure (laravel/serializable-closure)
 *
 * Usage: php benchmark.php
 */

require 'vendor/autoload.php';

use Laravel\SerializableClosure\SerializableClosure;
use Maantje\Charts\Bar\Bar;
use Maantje\Charts\Bar\BarGroup;
use Maantje\Charts\Bar\Bars;
use Maantje\Charts\Chart;
use Maantje\Charts\YAxis;

// Initialize Opis v4
Opis\Closure\Serializer::init();

// Get version info from composer.lock
function getInstalledVersion(string $package): string
{
    static $packages = null;
    if ($packages === null) {
        $lock = json_decode(file_get_contents(__DIR__ . '/composer.lock'), true);
        $packages = [];
        foreach ($lock['packages'] ?? [] as $p) {
            $packages[$p['name']] = $p['version'];
        }
        foreach ($lock['packages-dev'] ?? [] as $p) {
            $packages[$p['name']] = $p['version'];
        }
    }
    return $packages[$package] ?? 'unknown';
}

// Get Serializor version from composer.json (this is the project itself)
$composerJson = json_decode(file_get_contents(__DIR__ . '/composer.json'), true);
$serializorVersion = $composerJson['version'] ?? trim(shell_exec('git describe --tags 2>/dev/null') ?: 'dev-master');

$versions = [
    'serializor' => $serializorVersion,
    'opis' => getInstalledVersion('opis/closure'),
    'laravel' => getInstalledVersion('laravel/serializable-closure'),
];

echo "Closure Serialization Benchmark\n";
echo "================================\n";
echo "PHP " . PHP_VERSION . " on " . PHP_OS . "\n";
echo "Libraries:\n";
echo "  - Serializor: {$versions['serializor']}\n";
echo "  - Opis/Closure: {$versions['opis']}\n";
echo "  - Laravel: {$versions['laravel']}\n";
echo "\n";

$iterations = [
    'simple_closure' => 500,
    'closure_with_use' => 500,
    'closure_with_multiple_captures' => 500,
    'closure_with_object' => 500,
    'closure_with_nested_closures' => 500,
    'named_function' => 500,
    'static_method' => 500,
    'instance_method' => 500,
];

$results = [
    'serializor' => [],
    'opis' => [],
    'laravel' => [],
];

$colors = [
    'serializor' => '#4CAF50',
    'opis' => '#2196F3',
    'laravel' => '#FF5722',
];

/**
 * Generate unique closure files for cold benchmarking.
 * Each file contains multiple unique closures to ensure no caching.
 */
function generateClosureFiles(int $numFiles, int $closuresPerFile, string $type, string $libName = ''): array
{
    $tmpDir = sys_get_temp_dir() . '/benchmark_closures_' . getmypid();
    @mkdir($tmpDir, 0755, true);

    // Use library name in namespace to avoid redeclaration between library runs
    $nsPrefix = $libName ? ucfirst($libName) . '_' : '';

    $files = [];
    for ($f = 0; $f < $numFiles; $f++) {
        $file = "$tmpDir/closures_{$type}_{$libName}_{$f}.php";

        // Different structure for named functions and methods
        if ($type === 'named_function') {
            $code = "<?php\nnamespace BenchmarkFixtures_{$nsPrefix}{$f};\n\n";
            // Define named functions first
            for ($c = 0; $c < $closuresPerFile; $c++) {
                $id = $f * $closuresPerFile + $c;
                $code .= "function benchFunc_{$id}(int \$x = {$id}): int {\n";
                $code .= "    return \$x * 2 + {$id};\n";
                $code .= "}\n\n";
            }
            // Return array of first-class callables
            $code .= "return [\n";
            for ($c = 0; $c < $closuresPerFile; $c++) {
                $id = $f * $closuresPerFile + $c;
                $code .= "    benchFunc_{$id}(...),\n";
            }
            $code .= "];\n";
        } elseif ($type === 'static_method') {
            $code = "<?php\nnamespace BenchmarkFixtures_{$nsPrefix}{$f};\n\n";
            // Define class with static methods
            $code .= "class BenchClass_{$f} {\n";
            for ($c = 0; $c < $closuresPerFile; $c++) {
                $id = $f * $closuresPerFile + $c;
                $code .= "    public static function method_{$id}(int \$x = {$id}): int {\n";
                $code .= "        return \$x * 3 + {$id};\n";
                $code .= "    }\n\n";
            }
            $code .= "}\n\n";
            // Return array of first-class callables
            $code .= "return [\n";
            for ($c = 0; $c < $closuresPerFile; $c++) {
                $id = $f * $closuresPerFile + $c;
                $code .= "    BenchClass_{$f}::method_{$id}(...),\n";
            }
            $code .= "];\n";
        } elseif ($type === 'instance_method') {
            $code = "<?php\nnamespace BenchmarkFixtures_{$nsPrefix}{$f};\n\n";
            // Define class with instance methods
            $code .= "class BenchInstance_{$f} {\n";
            $code .= "    private int \$multiplier;\n";
            $code .= "    public function __construct(int \$m) { \$this->multiplier = \$m; }\n\n";
            for ($c = 0; $c < $closuresPerFile; $c++) {
                $id = $f * $closuresPerFile + $c;
                $code .= "    public function method_{$id}(int \$x = {$id}): int {\n";
                $code .= "        return \$x * \$this->multiplier + {$id};\n";
                $code .= "    }\n\n";
            }
            $code .= "}\n\n";
            // Return array of first-class callables bound to instances
            $code .= "\$obj = new BenchInstance_{$f}({$f});\n";
            $code .= "return [\n";
            for ($c = 0; $c < $closuresPerFile; $c++) {
                $id = $f * $closuresPerFile + $c;
                $code .= "    \$obj->method_{$id}(...),\n";
            }
            $code .= "];\n";
        } else {
            // Anonymous closures
            $code = "<?php\nreturn [\n";

            for ($c = 0; $c < $closuresPerFile; $c++) {
                $id = $f * $closuresPerFile + $c;
                // Each closure on its own line(s) to avoid Serializor's same-line detection issues
                switch ($type) {
                    case 'simple':
                        $code .= "    fn() => 'closure_{$id}',\n";
                        break;
                    case 'with_use':
                        // Split across lines
                        $code .= "    (function() {\n";
                        $code .= "        \$v{$id} = {$id};\n";
                        $code .= "        return fn() => \$v{$id};\n";
                        $code .= "    })(),\n";
                        break;
                    case 'complex':
                        $code .= "    (function() {\n";
                        $code .= "        \$a{$id} = {$id};\n";
                        $code .= "        \$b{$id} = " . ($id + 1) . ";\n";
                        $code .= "        return fn() => \$a{$id} + \$b{$id};\n";
                        $code .= "    })(),\n";
                        break;
                    case 'with_object':
                        $code .= "    (function() {\n";
                        $code .= "        \$obj{$id} = (object)['id' => {$id}];\n";
                        $code .= "        return fn() => \$obj{$id}->id;\n";
                        $code .= "    })(),\n";
                        break;
                    case 'nested':
                        $code .= "    (function() {\n";
                        $code .= "        \$n{$id} = fn(\$x) =>\n";
                        $code .= "            fn(\$y) => \$x + \$y + {$id};\n";
                        $code .= "        return fn() => \$n{$id}(1)(2);\n";
                        $code .= "    })(),\n";
                        break;
                    default:
                        $code .= "    fn() => {$id},\n";
                }
            }

            $code .= "];\n";
        }

        file_put_contents($file, $code);
        $files[] = $file;
    }

    return $files;
}

function cleanupClosureFiles(): void
{
    $tmpDir = sys_get_temp_dir() . '/benchmark_closures_' . getmypid();
    if (is_dir($tmpDir)) {
        foreach (glob("$tmpDir/*.php") as $file) {
            @unlink($file);
        }
        @rmdir($tmpDir);
    }
}

function benchmark(callable $serializeFn, callable $unserializeFn, string $type, int $iterations, string $libName = ''): array
{
    // Generate unique closures from unique files - guarantees cold performance
    $numFiles = 50;
    $closuresPerFile = (int) ceil($iterations / $numFiles);
    // Include library name in generation to avoid function redeclaration conflicts
    $files = generateClosureFiles($numFiles, $closuresPerFile, $type, $libName);

    // Load all closures
    $closures = [];
    foreach ($files as $file) {
        $closures = array_merge($closures, require $file);
    }
    $closures = array_slice($closures, 0, $iterations);

    // Measure serialize (each closure is unique - cold)
    $serializeStart = microtime(true);
    $serializedData = [];
    foreach ($closures as $i => $closure) {
        $serializedData[$i] = $serializeFn($closure);
    }
    $serializeTime = microtime(true) - $serializeStart;

    // Measure unserialize (each serialized closure is unique - cold)
    $unserializeStart = microtime(true);
    foreach ($serializedData as $serialized) {
        $unserialized = $unserializeFn($serialized);
    }
    $unserializeTime = microtime(true) - $unserializeStart;

    return [
        'serialize_time' => $serializeTime * 1000,
        'unserialize_time' => $unserializeTime * 1000,
        'iterations' => $iterations,
    ];
}

// Map test case names to closure types for file generation
$testCaseTypes = [
    'simple_closure' => 'simple',
    'closure_with_use' => 'with_use',
    'closure_with_multiple_captures' => 'complex',
    'closure_with_object' => 'with_object',
    'closure_with_nested_closures' => 'nested',
    'named_function' => 'named_function',
    'static_method' => 'static_method',
    'instance_method' => 'instance_method',
];

// Libraries configuration
$libraries = [
    'serializor' => [
        'serialize' => fn($c) => Serializor::serialize($c),
        'unserialize' => fn($s) => Serializor::unserialize($s),
    ],
    'opis' => [
        'serialize' => fn($c) => Opis\Closure\serialize($c),
        'unserialize' => fn($s) => Opis\Closure\unserialize($s),
    ],
    'laravel' => [
        'serialize' => fn($c) => serialize(new SerializableClosure($c)),
        'unserialize' => fn($s) => unserialize($s)->getClosure(),
    ],
];

echo "Running benchmarks (cold - unique closures from unique files)...\n\n";

foreach ($testCaseTypes as $caseName => $closureType) {
    $numIterations = $iterations[$caseName] ?? 1000;

    echo "  $caseName ($numIterations iterations)\n";

    foreach ($libraries as $libName => $lib) {
        try {
            $result = benchmark(
                $lib['serialize'],
                $lib['unserialize'],
                $closureType,
                $numIterations,
                $libName
            );
            $results[$libName][$caseName] = $result;
            printf("    %s: %.2fms serialize, %.2fms unserialize\n",
                $libName,
                $result['serialize_time'],
                $result['unserialize_time']
            );
        } catch (Throwable $e) {
            $results[$libName][$caseName] = ['error' => $e->getMessage()];
            echo "    $libName: UNSUPPORTED - " . $e->getMessage() . "\n";
        }
    }
    echo "\n";
}

// Cleanup temp files
cleanupClosureFiles();

// Save JSON results with metadata
$output = [
    'meta' => [
        'php_version' => PHP_VERSION,
        'os' => PHP_OS,
        'date' => date('Y-m-d H:i:s'),
        'versions' => $versions,
    ],
    'results' => $results,
];
file_put_contents('benchmark_results.json', json_encode($output, JSON_PRETTY_PRINT));
echo "Results saved to benchmark_results.json\n\n";

// Generate charts
echo "Generating charts...\n";

function generateChart(array $results, string $metric, string $title, array $colors): string
{
    $testCases = array_keys($results['serializor']);
    $maxValue = 0;

    // Find max value for scaling
    foreach ($results as $libResults) {
        foreach ($libResults as $caseResult) {
            if (isset($caseResult[$metric])) {
                $maxValue = max($maxValue, $caseResult[$metric]);
            }
        }
    }

    $barGroups = [];
    foreach ($testCases as $caseName) {
        $bars = [];
        foreach (['serializor', 'opis', 'laravel'] as $lib) {
            $value = $results[$lib][$caseName][$metric] ?? 0;
            $bars[] = new Bar(
                value: $value,
                color: $colors[$lib],
                radius: 4,
            );
        }
        $barGroups[] = new BarGroup(
            name: str_replace('_', ' ', $caseName),
            bars: $bars,
        );
    }

    $chart = new Chart(
        width: 900,
        height: 400,
        yAxis: new YAxis(
            minValue: 0,
            maxValue: ceil($maxValue * 1.1),
            title: $metric === 'serialize_time' || $metric === 'unserialize_time' ? 'Time (ms)' : 'Memory (bytes)',
        ),
        series: [new Bars(bars: $barGroups)],
    );

    return $chart->render();
}

// Generate serialization time chart
$serializeChart = generateChart($results, 'serialize_time', 'Serialization Time', $colors);
file_put_contents('docs/serialization-benchmark.svg', $serializeChart);
echo "  Created docs/serialization-benchmark.svg\n";

// Generate unserialization time chart
$unserializeChart = generateChart($results, 'unserialize_time', 'Unserialization Time', $colors);
file_put_contents('docs/unserialization-benchmark.svg', $unserializeChart);
echo "  Created docs/unserialization-benchmark.svg\n";

// Generate legend
$legend = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="400" height="40">
  <rect x="10" y="10" width="20" height="20" fill="{$colors['serializor']}" rx="4"/>
  <text x="35" y="25" font-family="sans-serif" font-size="14">Serializor</text>
  <rect x="130" y="10" width="20" height="20" fill="{$colors['opis']}" rx="4"/>
  <text x="155" y="25" font-family="sans-serif" font-size="14">Opis/Closure</text>
  <rect x="270" y="10" width="20" height="20" fill="{$colors['laravel']}" rx="4"/>
  <text x="295" y="25" font-family="sans-serif" font-size="14">Laravel</text>
</svg>
SVG;
file_put_contents('docs/benchmark-legend.svg', $legend);
echo "  Created docs/benchmark-legend.svg\n";

// Generate BENCHMARKS.md
echo "  Generating BENCHMARKS.md...\n";

$md = "<!-- This file is auto-generated by benchmark.php. Do not edit manually. -->\n";
$md .= "# Benchmark Results\n\n";
$md .= "**Last updated:** " . date('Y-m-d H:i:s') . "\n\n";
$md .= "**Environment:**\n";
$md .= "- PHP " . PHP_VERSION . " on " . PHP_OS . "\n";
$md .= "- Serializor: {$versions['serializor']}\n";
$md .= "- Opis/Closure: {$versions['opis']}\n";
$md .= "- Laravel Serializable Closure: {$versions['laravel']}\n\n";

$md .= "## Library Comparison\n\n";
$md .= "| Feature | Serializor | Opis | Laravel |\n";
$md .= "|---------|------------|------|--------|\n";
$md .= "| Transparent serialization | ✅ | ✅ | ❌ (requires wrapper) |\n";
$md .= "| Named functions | ✅ | ✅ | ❌ |\n";
$md .= "| First-class callables | ✅ | ✅ | ❌ |\n";
$md .= "| Objects containing closures | ✅ | ✅ | ❌ |\n";
$md .= "| Closures with shared state | ✅ | ✅ | ❌ |\n";
$md .= "\n";
$md .= "> **Note:** Laravel's SerializableClosure requires explicitly wrapping each closure before serialization.\n";
$md .= "> It cannot transparently serialize data structures containing closures, objects with closure properties,\n";
$md .= "> or first-class callables (e.g., `strlen(...)`, `\$obj->method(...)`).\n\n";

$md .= "## Summary\n\n";
$md .= "| Test Case | Serializor | Opis | Laravel | Winner (serialize) |\n";
$md .= "|-----------|------------|------|---------|--------------------|\n";

foreach ($results['serializor'] as $caseName => $result) {
    $serializorResult = $results['serializor'][$caseName] ?? [];
    $opisResult = $results['opis'][$caseName] ?? [];
    $laravelResult = $results['laravel'][$caseName] ?? [];

    $serializorTime = $serializorResult['serialize_time'] ?? null;
    $opisTime = $opisResult['serialize_time'] ?? null;
    $laravelTime = $laravelResult['serialize_time'] ?? null;

    $serializorStr = isset($serializorResult['error']) ? '❌' : ($serializorTime !== null ? sprintf('%.1fms', $serializorTime) : 'N/A');
    $opisStr = isset($opisResult['error']) ? '❌' : ($opisTime !== null ? sprintf('%.1fms', $opisTime) : 'N/A');
    $laravelStr = isset($laravelResult['error']) ? '❌' : ($laravelTime !== null ? sprintf('%.1fms', $laravelTime) : 'N/A');

    // Determine winner (only from successful runs)
    $times = array_filter([
        'Serializor' => $serializorTime,
        'Opis' => $opisTime,
        'Laravel' => $laravelTime,
    ], fn($t) => $t !== null);
    $winner = $times ? array_keys($times, min($times))[0] : 'N/A';

    $displayName = str_replace('_', ' ', $caseName);
    $md .= "| {$displayName} | {$serializorStr} | {$opisStr} | {$laravelStr} | {$winner} |\n";
}

$md .= "\n## Serialization Time\n\n";
$md .= "![Serialization Benchmark](docs/serialization-benchmark.svg)\n\n";

$md .= "## Unserialization Time\n\n";
$md .= "![Unserialization Benchmark](docs/unserialization-benchmark.svg)\n\n";

$md .= "![Legend](docs/benchmark-legend.svg)\n\n";

$md .= "## Detailed Results\n\n";

foreach ($results['serializor'] as $caseName => $result) {
    $displayName = str_replace('_', ' ', $caseName);
    $md .= "### " . ucwords($displayName) . "\n\n";
    $md .= "| Library | Serialize | Unserialize | Total |\n";
    $md .= "|---------|-----------|-------------|-------|\n";

    foreach (['serializor', 'opis', 'laravel'] as $lib) {
        $libResult = $results[$lib][$caseName] ?? null;
        if ($libResult && isset($libResult['serialize_time'])) {
            $serialize = sprintf('%.2fms', $libResult['serialize_time']);
            $unserialize = sprintf('%.2fms', $libResult['unserialize_time']);
            $total = sprintf('%.2fms', $libResult['serialize_time'] + $libResult['unserialize_time']);
        } elseif ($libResult && isset($libResult['error'])) {
            $serialize = '❌ Unsupported';
            $unserialize = '❌';
            $total = '❌';
        } else {
            $serialize = 'N/A';
            $unserialize = 'N/A';
            $total = 'N/A';
        }
        $libName = ucfirst($lib);
        $md .= "| {$libName} | {$serialize} | {$unserialize} | {$total} |\n";
    }
    $md .= "\n";
}

$md .= "---\n\n";
$md .= "*Generated by `php benchmark.php`*\n";

file_put_contents('BENCHMARKS.md', $md);
echo "  Created BENCHMARKS.md\n";

echo "\nBenchmark complete!\n";
