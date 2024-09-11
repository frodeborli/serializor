<?php

declare(strict_types=1);

namespace Serializor;

use Closure;
use ReflectionClass;
use ReflectionFunction;
use ReflectionObject;
use ReflectionProperty;
use ReflectionReference;
use Reflector;
use WeakMap;

use function hash;
use function is_object;

/**
 * Provides Reflection objects for various use cases and caches the reflection
 * instances to avoid excessive garbage collection.
 *
 * @package Serializor
 */
final class Reflect
{

    private static bool $initialized = false;
    private static array $reflectionClassCache = [];
    private static WeakMap $reflectionObjectCache;
    private static WeakMap $reflectionFunctionCache;

    public static function getVariableId(mixed &$value): string
    {
        return ReflectionReference::fromArrayElement([&$value], 0)->getId();
    }

    /**
     * Provide a hash unique for the Reflection instance.
     */
    public static function getHash(Reflector $reflector): string
    {
        return hash('sha256', (string) $reflector);
    }

    /**
     * Get and cache a ReflectionFunction
     *
     * @param callable $function
     * @return ReflectionFunction
     */
    public static function getReflectionFunction(callable $function): ReflectionFunction
    {
        if (!self::$initialized) self::init();
        if (!isset(self::$reflectionFunctionCache[$function])) {
            self::$reflectionFunctionCache[$function] = new ReflectionFunction(Closure::fromCallable($function));
        }
        return self::$reflectionFunctionCache[$function];
    }

    private static function getReflectionObject(object $value): ReflectionObject
    {
        if (!self::$initialized) self::init();
        if (!isset(self::$reflectionObjectCache[$value])) {
            self::$reflectionObjectCache[$value] = new ReflectionObject($value);
        }
        return self::$reflectionObjectCache[$value];
    }

    public static function getReflectionClass(object|string $value): ReflectionClass
    {
        if (is_object($value)) {
            return self::getReflectionObject($value);
        }
        if (!isset(self::$reflectionClassCache[$value])) {
            self::$reflectionClassCache[$value] = new ReflectionClass($value);
        }
        return self::$reflectionClassCache[$value];
    }

    /**
     * Finds all reflection properties for an object and it's ancestors,
     * in an array where the key identifies uniquely the property with
     * the class name followed by \0 as a prefix.
     *
     * @param class-string<mixed> $className
     * @return array<string,ReflectionProperty>
     */
    public static function getReflectionProperties(string $className): array
    {
        if (!isset(self::$propertyReflectors[$className])) {
            $topClass = $className;
            self::$propertyReflectors[$className] = [];
            $rc = self::getReflectionClass($className);
            do {
                if ($className === $topClass) {
                    $prefix = '';
                } else {
                    $prefix = $className . "\0";
                }
                foreach ($rc->getProperties() as $rp) {
                    self::$propertyReflectors[$className][$prefix . $rp->getName()] = self::$propertyReflectors[$className][$prefix . $rp->getName()] ?? $rp;
                }
            } while ($rc = $rc->getParentClass());
        }
        return self::$propertyReflectors[$className];
    }

    /**
     * Caches ReflectionProperty instances for the class and
     * its ancestors.
     *
     * @var array<class-string<mixed>,ReflectionProperty[]>
     */
    private static array $propertyReflectors = [];

    /**
     * Instantiate static properties
     */
    private static function init(): void
    {
        if (!self::$initialized) {
            self::$initialized = true;
            self::$reflectionObjectCache = new WeakMap;
            self::$reflectionFunctionCache = new WeakMap;
        }
    }
}
