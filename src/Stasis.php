<?php

declare(strict_types=1);

namespace Serializor;

use Closure;

/**
 * This class stores all data about objects so that they can be
 * properly unserialized. Arbitrary data can be stored in the
 * Stasis::$p array.
 */
final class Stasis
{
    private static ?\WeakMap $results = null;


    private int $i;

    /**
     * The class name.
     *
     * @var class-string
     */
    private string $c;

    /**
     * The serialized class members.
     */
    public array $p = [];

    /**
     * The object vars that are not class members.
     */
    public array $v = [];

    /**
     * @var Closure[]
     */
    public array $whenResolvedListeners = [];

    /**
     * @param class-string $className
     *
     * @return void
     */
    public function __construct(string $className)
    {
        $this->i = \mt_rand(0, \PHP_INT_MAX);
        $this->c = $className;
    }

    public function __serialize(): array
    {
        return [$this->c, $this->i, $this->p, $this->v];
    }

    public function __unserialize(array $data): void
    {
        [$this->c, $this->i, $this->p, $this->v] = $data;
    }

    public function whenResolved(Closure $listener): void
    {
        $this->whenResolvedListeners[] = $listener;
    }

    public function setInstance(mixed $value): void
    {
        self::init();
        self::$results[$this] = [&$value];
        foreach ($this->whenResolvedListeners as $listener) {
            $listener($value, $this);
        }
        $this->whenResolvedListeners = [];
    }

    public function hasInstance(): bool
    {
        self::init();

        return isset(self::$results[$this]);
    }

    private function &getCachedInstance(): mixed
    {
        self::init();
        $a = self::$results[$this];

        return $a[0];
    }

    public function getClassName(): string
    {
        return $this->c;
    }

    public function &getInstance(): mixed
    {
        if ($this->hasInstance()) {
            return $this->getCachedInstance();
        }
        $rc = new \ReflectionClass($this->c);
        $newInstance = $rc->newInstanceWithoutConstructor();

        if (\method_exists($newInstance, '__unserialize')) {
            $newInstance->__unserialize($this->p);
            $this->setInstance($newInstance);

            return $newInstance;
        }

        if ($rc->isInternal()) {
            foreach ($this->p as $k => $v) {
                $newInstance->$k = &$this->p[$k];
            }
            $this->setInstance($newInstance);

            return $newInstance;
        }

        $properties = $this->p;
        $propertiesToSet = [];
        foreach (Reflect::getReflectionProperties($this->c) as $name => $rp) {
            $parts = \explode("\0", $name, 2);
            if (isset($parts[1])) {
                $propertiesToSet[$parts[0]][$parts[1]] = $rp;
            } else {
                $propertiesToSet[$this->c][$name] = $rp;
            }
        }
        $deferred = [];
        foreach ($propertiesToSet as $className => $props) {
            if ($className === $this->c) {
                $prefix = '';
            } else {
                $prefix = $className . "\0";
            }
            $self = &$this;
            \Closure::bind(function () use ($props, $properties, $prefix, &$deferred, $self) {
                foreach ($props as $name => $rp) {
                    if ($rp->isStatic()) {
                        continue;
                    }
                    if (!isset($properties[$name]) && !\array_key_exists($name, $properties)) {
                        continue;
                    }
                    $name = $prefix . $rp->getName();
                    if ($properties[$name] instanceof Stasis) {
                        if ($properties[$name]->hasInstance()) {
                            $rp->setValue($this, $properties[$name]->getInstance());
                        } else {
                            $properties[$name]->whenResolved(function ($instance) use ($rp, $properties, $name) {
                                $rp->setValue($this, $instance);
                            });
                        }
                    } else {
                        $rp->setValue($this, $properties[$name]);
                    }
                }
            }, $newInstance, $className)();
        }
        $this->setInstance($newInstance);

        while (!empty($deferred)) {
            $c = array_shift($deferred);
            if (!$c()) {
                $deferred[] = $c;
            }
        }

        return $newInstance;
    }

    /**
     * Save the state of an object so that it can be restored after
     * unserialization.
     */
    public static function from(object $source): Stasis
    {
        $className = \get_class($source);
        if ($className === \Closure::class) {
            throw new SerializerError("Can't serialize $className");
        }

        $rc = Reflect::getReflectionClass($className);
        if ($rc->isAnonymous()) {
            throw new SerializerError("Can't serialize anonymous classes");
        }

        if ($className === Stasis::class) {
            throw new SerializerError('Should not directly serialize a Stasis class');
        }

        $frozen = new Stasis(\get_class($source));

        if (\method_exists($source, '__serialize')) {
            $frozen->p = $source->__serialize();
        } else {
            $rps = Reflect::getReflectionProperties($className);
            foreach ($rps as $name => $rp) {
                if ($rp->isStatic() || !$rp->isInitialized($source)) {
                    continue;
                }
                $frozen->p[$name] = $rp->getValue($source);
            }
            foreach (\get_object_vars($source) as $name => $v) {
                if (!\array_key_exists($name, $frozen->p)) {
                    $frozen->p[$name] = &$v;
                }
            }
        }

        return $frozen;
    }

    private static function init(): void
    {
        if (self::$results === null) {
            self::$results = new \WeakMap();
        }
    }
}
