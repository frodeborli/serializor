<?php

declare(strict_types=1);

namespace Serializor;

use LogicException;
use ReflectionReference;
use Closure;
use ReflectionFunction;
use Serializor;
use Serializor\Box;
use Serializor\SerializerError;
use Serializor\Stasis;
use Throwable;
use WeakMap;

/**
 * Serializor provides a powerful way to serialize PHP values including
 * closures, anonymous classes, and other typically non-serializable types.
 */
class Codec
{
    /**
     * The secret key used to sign serialized values.
     */
    private string $secret;

    /**
     * Tracks the original value of a reference, for comparison.
     * @var array<string,mixed>
     */
    private array $referenceSources = [];

    /**
     * Tracks the new value of a reference for reuse.
     * @var array<string,mixed>
     */
    private array $referenceTargets = [];

    /**
     * Callbacks for when a reference is resolved.
     * @var array<string,Closure[]>
     */
    private array $referenceCallbacks = [];

    /**
     * Shortcuts to Stasis objects for efficient serialization.
     */
    private array $shortcuts = [];

    /**
     * @var WeakMap<object,object>
     */
    private WeakMap $encodedObjects;

    /**
     * Tracks strongly referenced objects.
     * @var array<int,true>
     */
    private array $stronglyReferenced = [];

    /**
     * Flag for weak context (WeakReference or WeakMap key).
     */
    private bool $inWeakContext = false;

    /**
     * @param string $secret A string secret for HMAC signing. Empty string disables signing.
     */
    public function __construct(string $secret = '')
    {
        $this->secret = $secret;
        $this->encodedObjects = new WeakMap();
    }

    /**
     * Perform serialization of a value.
     */
    public function serialize(mixed &$value): string
    {
        // Fast path: named functions and static methods (no nested objects)
        if ($value instanceof Closure) {
            $rf = new ReflectionFunction($value);
            if (!\str_starts_with($rf->getShortName(), '{closure')) {
                // Named callable
                $closureThis = $rf->getClosureThis();
                if ($closureThis === null) {
                    // Static method or named function - can use fast path
                    $stasis = CallableStasis::fromClosure($value, $rf);
                    $result = \serialize($stasis);
                    if ($this->secret !== '') {
                        return \hash_hmac('sha256', $result, $this->secret, false) . '|' . $result;
                    }
                    return $result;
                }
                // Instance method with bound $this - needs full pipeline to handle object
            }
        }

        // Force Stasis path for types with broken native serialization in older PHP
        $forceStasis = ($value instanceof \SplHeap) || ($value instanceof \SplPriorityQueue);

        $this->encodedObjects = new WeakMap();
        $this->referenceSources = [];
        $this->referenceTargets = [];
        $this->referenceCallbacks = [];
        $this->shortcuts = [];
        $this->stronglyReferenced = [];
        $this->inWeakContext = false;

        try {
            if ($forceStasis) {
                throw new LogicException('Type requires special handling');
            }
            $result = \serialize($value);
        } catch (Throwable) {
            $v = [&$value];
            $transformed = $this->transform($v, [], null);
            $this->markDeadWeakReferences();
            // Skip Box wrapper if result is a single simple Stasis
            $canSkipBox = $transformed[0] instanceof Stasis
                && \count($this->shortcuts) === 1
                && $transformed[0]->isSimple();
            if ($canSkipBox) {
                $result = \serialize($transformed[0]);
            } else {
                $result = \serialize(new Box($transformed, $this->shortcuts));
            }
        } finally {
            $this->referenceSources = [];
            $this->referenceTargets = [];
            $this->referenceCallbacks = [];
            $this->shortcuts = [];
            $this->stronglyReferenced = [];
            $this->inWeakContext = false;
        }

        if ($this->secret !== '') {
            $signature = \hash_hmac('sha256', $result, $this->secret, false);
            return $signature . '|' . $result;
        }

        return $result;
    }

    /**
     * Mark WeakReference/WeakMap Stasis objects as dead if not strongly referenced.
     */
    private function markDeadWeakReferences(): void
    {
        foreach ($this->shortcuts as $stasis) {
            if ($stasis instanceof WeakReferenceStasis) {
                $ref = $stasis->getRef();
                if (\is_object($ref)) {
                    $objId = \spl_object_id($ref);
                    if (!isset($this->stronglyReferenced[$objId])) {
                        $stasis->markDead();
                    }
                }
            } elseif ($stasis instanceof WeakMapStasis) {
                $keys = $stasis->getKeys();
                $dead = &$stasis->getDead();
                foreach ($keys as $i => $key) {
                    if (\is_object($key)) {
                        $objId = \spl_object_id($key);
                        if (!isset($this->stronglyReferenced[$objId])) {
                            $dead[$i] = true;
                        }
                    }
                }
            }
        }
    }

    /**
     * Transform a value into a serializable structure.
     */
    protected function &transform(mixed &$source, array $path, string|int|null $key): mixed
    {
        \assert(!($source === null || \is_scalar($source)), 'Trying to encode NULL or scalar');
        if ($key !== null) {
            $path[] = $key;
        }
        $sourceWrap = [&$source];
        $referenceId = ReflectionReference::fromArrayElement($sourceWrap, 0)->getId();

        if (isset($this->referenceSources[$referenceId])) {
            \assert($this->referenceSources[$referenceId][0] === $sourceWrap[0], 'The source value has changed during serialization');
            return $this->referenceTargets[$referenceId];
        }

        $this->referenceSources[$referenceId] = &$sourceWrap;

        // Objects can also be found via the WeakMap
        if (\is_object($source) && isset($this->encodedObjects[$source])) {
            if (!$this->inWeakContext) {
                $this->stronglyReferenced[\spl_object_id($source)] = true;
            }
            $result = $this->encodedObjects[$source];
            $this->referenceTargets[$referenceId] = &$result;
            return $result;
        }

        // Walk arrays recursively
        if (\is_array($source)) {
            $result = [];
            $this->referenceTargets[$referenceId] = &$result;
            foreach ($source as $k => &$v) {
                if (\is_scalar($v) || $v === null) {
                    $result[$k] = &$v;
                } else {
                    $result[$k] = &$this->transform($source[$k], $path, $k);
                }
            }
            return $this->referenceTargets[$referenceId];
        }

        // Try native serialization first
        try {
            $serialized = serialize($source);
            // Some types need special handling (broken in older PHP or need weak semantics)
            if (\str_contains($serialized, 'SplObjectStorage')
                || \str_contains($serialized, 'WeakReference')
                || \str_contains($serialized, 'SplMaxHeap')
                || \str_contains($serialized, 'SplMinHeap')
                || \str_contains($serialized, 'SplPriorityQueue')
            ) {
                throw new LogicException('Type requires special handling');
            }
            $target = $source;
            if (\is_object($source)) {
                $this->encodedObjects[$source] = $target;
                if (!$this->inWeakContext) {
                    $this->stronglyReferenced[\spl_object_id($source)] = true;
                }
            }
            $this->referenceTargets[$referenceId] = &$target;
            return $target;
        } catch (Throwable) {
            // Native serialization failed, use Stasis
        }

        // Create appropriate Stasis subclass
        $target = Stasis::from($source);
        $this->shortcuts[] = &$target;
        if (\is_object($source)) {
            $this->encodedObjects[$source] = $target;
            if (!$this->inWeakContext) {
                $this->stronglyReferenced[\spl_object_id($source)] = true;
            }
        }
        $this->referenceTargets[$referenceId] = &$target;

        // Handle recursive transformation based on Stasis type
        $this->transformStasisChildren($target, $path);

        return $target;
    }

    /**
     * Transform child values within a Stasis object.
     */
    private function transformStasisChildren(Stasis $target, array $path): void
    {
        $wasInWeakContext = $this->inWeakContext;

        if ($target instanceof WeakReferenceStasis) {
            // WeakReference: entire content is weak context - nothing to transform
            // The ref object will be handled by markDeadWeakReferences
        } elseif ($target instanceof WeakMapStasis) {
            // WeakMap: keys are weak context, values are strong context
            $this->inWeakContext = true;
            $keys = &$target->getKeys();
            foreach ($keys as $i => &$key) {
                if (!\is_scalar($key) && $key !== null) {
                    $keys[$i] = &$this->transform($key, $path, 'k' . $i);
                }
            }
            $this->inWeakContext = $wasInWeakContext;

            $values = &$target->getValues();
            foreach ($values as $i => &$val) {
                if (!\is_scalar($val) && $val !== null) {
                    $values[$i] = &$this->transform($val, $path, 'v' . $i);
                }
            }
        } elseif ($target instanceof SplObjectStorageStasis) {
            $objects = &$target->getObjects();
            foreach ($objects as $i => &$obj) {
                if (!\is_scalar($obj) && $obj !== null) {
                    $objects[$i] = &$this->transform($obj, $path, 'o' . $i);
                }
            }
            $data = &$target->getData();
            foreach ($data as $i => &$d) {
                if (!\is_scalar($d) && $d !== null) {
                    $data[$i] = &$this->transform($d, $path, 'd' . $i);
                }
            }
        } elseif ($target instanceof SplHeapStasis) {
            $items = &$target->getItems();
            foreach ($items as $i => &$item) {
                if (!\is_scalar($item) && $item !== null) {
                    $items[$i] = &$this->transform($item, $path, 'h' . $i);
                }
            }
        } elseif ($target instanceof SplPriorityQueueStasis) {
            $items = &$target->getItems();
            foreach ($items as $i => &$item) {
                // Transform both data and priority (priority could be an object)
                if (!\is_scalar($item['data']) && $item['data'] !== null) {
                    $items[$i]['data'] = &$this->transform($item['data'], $path, 'pq' . $i . 'd');
                }
                if (!\is_scalar($item['priority']) && $item['priority'] !== null) {
                    $items[$i]['priority'] = &$this->transform($item['priority'], $path, 'pq' . $i . 'p');
                }
            }
        } elseif ($target instanceof AnonymousClassStasis) {
            $props = &$target->getProps();
            foreach ($props as $k => &$v) {
                if (!\is_scalar($v) && $v !== null) {
                    $props[$k] = &$this->transform($v, $path, $k);
                }
            }
        } elseif ($target instanceof ObjectStasis) {
            foreach ($target->p as $k => &$v) {
                if (!\is_scalar($v) && $v !== null) {
                    $target->p[$k] = &$this->transform($v, $path, $k);
                }
            }
        } elseif ($target instanceof ClosureStasis) {
            // Transform use variables
            $use = &$target->getUse();
            foreach ($use as $k => &$v) {
                if (!\is_scalar($v) && $v !== null) {
                    $use[$k] = &$this->transform($v, $path, 'use:' . $k);
                }
            }
            // Transform $this if present
            $thisObj = $target->getThis();
            if ($thisObj !== null) {
                $target->setThis($this->transform($thisObj, $path, 'this'));
            }
        } elseif ($target instanceof BoundMethodStasis) {
            // Transform the bound object
            $obj = $target->getObject();
            if ($obj !== null) {
                $target->setObject($this->transform($obj, $path, 'object'));
            }
        }
        // CallableStasis: no nested objects to transform
    }

    /**
     * Perform unserialization of a string.
     */
    public function &unserialize(string $value): mixed
    {
        try {
            $this->referenceSources = [];
            $this->referenceTargets = [];
            $this->referenceCallbacks = [];

            if ($this->secret !== '') {
                $signatureEndOffset = \strpos($value, '|') ?: 0;
                $signature = \substr($value, 0, $signatureEndOffset);
                $value = \substr($value, $signatureEndOffset + 1);
                if ($signature !== \hash_hmac('sha256', $value, $this->secret, false)) {
                    throw new SerializerError('Invalid signature in the serialized data');
                }
            }

            $result = unserialize($value);

            // Handle standalone Stasis (e.g., CallableStasis for named functions)
            if ($result instanceof Stasis) {
                $result = $result->getInstance();
                return $result;
            }

            if ($result instanceof Box) {
                foreach ($result->shortcuts as &$shortcut) {
                    if ($shortcut instanceof Stasis) {
                        $this->resolve($shortcut);
                    }
                }
                return $result->val;
            }

            return $result;
        } finally {
            $this->referenceSources = [];
            $this->referenceTargets = [];
            $this->referenceCallbacks = [];
        }
    }

    private function resolve(array|Stasis &$source): void
    {
        $sourceWrap = [&$source];
        $referenceId = ReflectionReference::fromArrayElement($sourceWrap, 0)->getId();
        if (isset($this->referenceCallbacks[$referenceId]) || \array_key_exists($referenceId, $this->referenceCallbacks)) {
            $this->referenceCallbacks[$referenceId][] = static function (mixed &$target) use (&$source) {
                $source = $target;
            };
            return;
        }
        $this->referenceCallbacks[$referenceId] = [];

        if (\is_array($source)) {
            foreach ($source as &$v) {
                if (\is_array($v) || $v instanceof Stasis) {
                    $this->resolve($v);
                }
            }
        } elseif ($source->hasInstance()) {
            $source = $source->getInstance();
        } else {
            // Resolve children first for Stasis types with nested data
            $this->resolveStasisChildren($source);

            // Get the instance
            $source = $source->getInstance();

            if (!empty($this->referenceCallbacks[$referenceId])) {
                foreach ($this->referenceCallbacks[$referenceId] as $cb) {
                    $cb($source);
                }
            }
            unset($this->referenceCallbacks[$referenceId]);
        }
    }

    /**
     * Resolve child values within a Stasis object.
     */
    private function resolveStasisChildren(Stasis $source): void
    {
        if ($source instanceof ObjectStasis) {
            foreach ($source->p as &$v) {
                if (\is_array($v) || $v instanceof Stasis) {
                    $this->resolve($v);
                }
            }
        } elseif ($source instanceof WeakMapStasis) {
            $keys = &$source->getKeys();
            foreach ($keys as &$key) {
                if (\is_array($key) || $key instanceof Stasis) {
                    $this->resolve($key);
                }
            }
            $values = &$source->getValues();
            foreach ($values as &$val) {
                if (\is_array($val) || $val instanceof Stasis) {
                    $this->resolve($val);
                }
            }
        } elseif ($source instanceof SplObjectStorageStasis) {
            $objects = &$source->getObjects();
            foreach ($objects as &$obj) {
                if (\is_array($obj) || $obj instanceof Stasis) {
                    $this->resolve($obj);
                }
            }
            $data = &$source->getData();
            foreach ($data as &$d) {
                if (\is_array($d) || $d instanceof Stasis) {
                    $this->resolve($d);
                }
            }
        } elseif ($source instanceof SplHeapStasis) {
            $items = &$source->getItems();
            foreach ($items as &$item) {
                if (\is_array($item) || $item instanceof Stasis) {
                    $this->resolve($item);
                }
            }
        } elseif ($source instanceof SplPriorityQueueStasis) {
            $items = &$source->getItems();
            foreach ($items as &$item) {
                if (\is_array($item['data']) || $item['data'] instanceof Stasis) {
                    $this->resolve($item['data']);
                }
                if (\is_array($item['priority']) || $item['priority'] instanceof Stasis) {
                    $this->resolve($item['priority']);
                }
            }
        } elseif ($source instanceof AnonymousClassStasis) {
            $props = &$source->getProps();
            foreach ($props as &$v) {
                if (\is_array($v) || $v instanceof Stasis) {
                    $this->resolve($v);
                }
            }
        } elseif ($source instanceof ClosureStasis) {
            $use = &$source->getUse();
            foreach ($use as &$v) {
                if (\is_array($v) || $v instanceof Stasis) {
                    $this->resolve($v);
                }
            }
            $thisObj = $source->getThis();
            if ($thisObj instanceof Stasis) {
                $this->resolve($thisObj);
                $source->setThis($thisObj);
            }
        } elseif ($source instanceof BoundMethodStasis) {
            $obj = $source->getObject();
            if ($obj instanceof Stasis) {
                $this->resolve($obj);
                $source->setObject($obj);
            }
        }
        // CallableStasis: no nested Stasis children
    }
}
