<?php

namespace Serializor;

use Closure;
use ReflectionClass;
use ReflectionFunction;
use ReflectionReference;

class Debug
{
    private static array $longMemory = [];
    private static int $count = 0;

    public static function clear(): void
    {
        self::$longMemory = [];
        self::$count = 0;
    }

    public static function dump(mixed &$value, int $indent = 0, string|int|null $key = null, array $stackMemory = [], array &$shortMemory = []): void
    {
        try {
            $ind = \str_repeat('   ', $indent);
            if (\is_object($value)) {
                $refId = 'object' . \spl_object_id($value);
            } else {
                $refId = ReflectionReference::fromArrayElement([&$value], 0)->getId() . (\is_array($value) || \is_object($value) ? \spl_object_hash((object) $value) : '');
            }
            if (isset(self::$longMemory['@' . $refId])) {
                $varId = self::$longMemory['@' . $refId];
            } else {
                $varId = '{' . self::$count++ . '}';
                self::$longMemory['@' . $refId] = $varId;
            }
            $output = isset($shortMemory['output'][$varId]);
            $shortMemory['output'][$varId] = true;
            $seen = isset($stackMemory['seen'][$varId]);
            $stackMemory['seen'][$varId] = true;
            $varIdStr = ($seen ? '@' . $varId : "$varId") . ' ';
            if ($seen && (\is_array($value) || \is_object($value))) {
                echo $ind . ($key !== null ? (trim(var_export($key, true)) . " = ") : '') . "$varIdStr*RECURSION* (" . get_debug_type($value) . ")\n";
                return;
            } elseif ($output) {
                if (\is_scalar($value) || $value === null) {
                    $valueStr = trim(var_export($value, true)) . ' ';
                } else {
                    $valueStr = '';
                }
                echo $ind . ($key !== null ? (trim(var_export($key, true)) . " = ") : '') . "$varIdStr*REF* $valueStr(" . get_debug_type($value) . ")\n";
                return;
            } else
            if ($key !== null) {
                echo $ind . trim(var_export($key, true)) . " = " . $varIdStr;
            } else {
                echo $ind . $varIdStr;
            }
            if (is_array($value)) {

                if (empty($value)) {
                    echo "array[]\n";
                    return;
                } else {
                    echo "array[\n";
                }
                foreach ($value as $k => &$v) {
                    self::dump($v, $indent + 1, $k, $stackMemory, $shortMemory);
                }
                echo $ind . "]\n";
            } elseif ($value instanceof Closure) {
                $rc = new ReflectionFunction($value);
                if ($rc->getName() === '{closure}') {
                    echo "Closure";
                } else {
                    echo $rc->getName();
                }

                $args = [];
                foreach ($rc->getParameters() as $rp) {
                    $arg = '';
                    if ($rp->hasType()) {
                        $arg .= $rp->getType()->getName() . ' ';
                    }
                    if ($rp->isPassedByReference()) {
                        $arg .= '&';
                    }
                    if ($rp->isVariadic()) {
                        $arg .= '...';
                    }
                    $arg .= '$' . $rp->getName();
                    if ($rp->isDefaultValueAvailable()) {
                        '=' . trim(var_export($rp->getDefaultValue(), true));
                    }
                    $args[] = $arg;
                }
                echo "(" . implode(", ", $args) . ") {";
                ob_start();
                $self = $rc->getClosureThis();
                if ($self !== null) {
                    self::dump($self, $indent + 1, '$this', $stackMemory, $shortMemory);
                }
                $scopeClass = $rc->getClosureScopeClass()?->getName();
                if ($scopeClass !== null) {
                    self::dump($scopeClass, $indent + 1, 'self', $stackMemory, $shortMemory);
                }
                $uses = $rc->getClosureUsedVariables();
                if (!empty($uses)) {
                    self::dump($uses, $indent + 1, 'use', $stackMemory, $shortMemory);
                }
                $c = ob_get_contents();
                ob_end_clean();
                if ($c === '') {
                    echo "}\n";
                } else {
                    echo "\n$c$ind}\n";
                }
            } elseif (\is_object($value)) {
                echo \get_class($value) . " {\n";
                $rc = new ReflectionClass($value);
                foreach ($rc->getProperties() as $rp) {
                    if ($rp->isStatic()) {
                        continue;
                    }
                    $propValue = Closure::bind(function () use ($rp) {
                        $name = $rp->getName();
                        return $this->$name;
                    }, $value, $rc->getName())();
                    self::dump($propValue, $indent + 1, $rp->getName(), $stackMemory, $shortMemory);
                }
                echo $ind . "}\n";
            } elseif ($value === null) {
                echo trim(var_export($value, true)) . "\n";
            } else {
                echo trim(var_export($value, true)) . " (" . \get_debug_type($value) . ")\n";
            }
        } finally {
        }
    }
}

class DebugBox
{
    public function __construct(
        public readonly mixed $value,
        public readonly string $refId
    ) {}
}
