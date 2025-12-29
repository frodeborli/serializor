<?php

declare(strict_types=1);

namespace Serializor;

use Closure;
use ReflectionClass;
use ReflectionFunction;
use SplHeap;
use SplPriorityQueue;
use WeakMap;
use WeakReference;
use SplObjectStorage;

/**
 * Abstract base class for serializing values that can't be natively serialized.
 * Each subclass handles a specific type with typed properties for efficient serialization.
 */
abstract class Stasis
{
    /**
     * WeakMap for caching resolved instances to preserve object identity.
     */
    protected static ?WeakMap $results = null;

    /**
     * @var Closure[]
     */
    public array $whenResolvedListeners = [];

    /**
     * Custom factories for extending Stasis with user-defined types.
     * @var array<class-string, callable(object): ?Stasis>
     */
    private static array $customFactories = [];

    /**
     * Create the appropriate Stasis subclass for the given value.
     */
    public static function from(mixed $value): Stasis
    {
        // Check custom factories first
        if (\is_object($value)) {
            foreach (self::$customFactories as $class => $factory) {
                if ($value instanceof $class) {
                    $result = $factory($value);
                    if ($result !== null) {
                        return $result;
                    }
                }
            }
        }

        // Closures
        if ($value instanceof Closure) {
            $rf = new ReflectionFunction($value);
            $isAnonymous = \str_starts_with($rf->getShortName(), '{closure');

            if (!$isAnonymous) {
                // Named callable (function, static method, or instance method)
                if ($rf->getClosureThis() !== null) {
                    return BoundMethodStasis::fromClosure($value, $rf);
                }
                return CallableStasis::fromClosure($value, $rf);
            }
            return ClosureStasis::fromClosure($value, $rf);
        }

        // WeakReference
        if ($value instanceof WeakReference) {
            return WeakReferenceStasis::fromWeakReference($value);
        }

        // WeakMap
        if ($value instanceof WeakMap) {
            return WeakMapStasis::fromWeakMap($value);
        }

        // SplObjectStorage
        if ($value instanceof SplObjectStorage) {
            return SplObjectStorageStasis::fromStorage($value);
        }

        // SplHeap subclasses (SplMaxHeap, SplMinHeap)
        // Note: PHP 8.5 adds __serialize() but older versions don't have it
        if ($value instanceof SplHeap) {
            return SplHeapStasis::fromHeap($value);
        }

        // SplPriorityQueue
        // Note: PHP 8.5 adds __serialize() but older versions don't have it
        if ($value instanceof SplPriorityQueue) {
            return SplPriorityQueueStasis::fromQueue($value);
        }

        // Anonymous classes
        if (\is_object($value)) {
            $rc = new ReflectionClass($value);
            if ($rc->isAnonymous()) {
                return AnonymousClassStasis::fromObject($value, $rc);
            }
        }

        // Regular objects
        return ObjectStasis::fromObject($value);
    }

    /**
     * Register a custom factory for handling user-defined types.
     *
     * @param class-string $class The class name to handle
     * @param callable(object): ?Stasis $factory Factory that returns a Stasis or null to skip
     */
    public static function registerFactory(string $class, callable $factory): void
    {
        self::$customFactories[$class] = $factory;
    }

    /**
     * Restore the original value from this Stasis.
     */
    abstract public function &getInstance(): mixed;

    /**
     * Get the class name this Stasis represents.
     */
    abstract public function getClassName(): string;

    /**
     * Check if this Stasis can be serialized without Box wrapper.
     * Override in subclasses that support standalone serialization.
     */
    public function isSimple(): bool
    {
        return false;
    }

    /**
     * Add a callback to be invoked when this Stasis is resolved.
     */
    public function whenResolved(Closure $listener): void
    {
        $this->whenResolvedListeners[] = $listener;
    }

    /**
     * Store the resolved instance and notify listeners.
     */
    public function setInstance(mixed $value): void
    {
        self::init();
        self::$results[$this] = [&$value];
        foreach ($this->whenResolvedListeners as $listener) {
            $listener($value, $this);
        }
        $this->whenResolvedListeners = [];
    }

    /**
     * Check if this Stasis has already been resolved.
     */
    public function hasInstance(): bool
    {
        self::init();
        return isset(self::$results[$this]);
    }

    /**
     * Get the cached instance if it exists.
     */
    protected function &getCachedInstance(): mixed
    {
        self::init();
        $a = self::$results[$this];
        return $a[0];
    }

    protected static function init(): void
    {
        if (self::$results === null) {
            self::$results = new WeakMap();
        }
    }

    /**
     * Get object properties including private/protected from parent classes.
     */
    public static function getObjectProperties(object $value): array
    {
        $ro = new \ReflectionObject($value);
        $cro = $ro;
        $result = [];
        do {
            $prefix = '';
            foreach ($cro->getProperties() as $rp) {
                if ($rp->isStatic()) {
                    continue;
                }
                // PHP 8.4+: Skip virtual properties (computed properties with only get hook)
                if (\method_exists($rp, 'isVirtual') && $rp->isVirtual()) {
                    continue;
                }
                if ($rp->isInitialized($value)) {
                    $result[$prefix . $rp->getName()] = $rp->getValue($value);
                }
            }
            $cro = $cro->getParentClass();
            if ($cro !== false) {
                $prefix = $cro->getName() . "\0";
            }
        } while ($cro !== false);

        return $result;
    }

    /**
     * Set object properties including private/protected from parent classes.
     */
    public static function setObjectProperties(object $value, array $properties): void
    {
        $ro = new \ReflectionObject($value);
        $cro = $ro;
        $prefix = '';
        do {
            \Closure::bind(function () use ($value, $cro, $properties, $prefix) {
                foreach ($cro->getProperties() as $rp) {
                    if ($rp->isStatic()) {
                        continue;
                    }
                    // PHP 8.4+: Skip virtual properties (computed properties with only get hook)
                    if (\method_exists($rp, 'isVirtual') && $rp->isVirtual()) {
                        continue;
                    }
                    $name = $prefix . $rp->getName();
                    if (isset($properties[$name]) || array_key_exists($name, $properties)) {
                        $rp->setValue($value, $properties[$name]);
                    }
                }
            }, $value, $cro->getName())();

            $cro = $cro->getParentClass();
            if ($cro !== false) {
                $prefix = $cro->getName() . "\0";
            }
        } while ($cro !== false);
    }
}
