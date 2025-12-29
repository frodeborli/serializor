<?php

declare(strict_types=1);

namespace Serializor;

use Closure;

/**
 * Stasis for regular objects that need custom serialization handling.
 */
final class ObjectStasis extends Stasis
{
    /**
     * The class name.
     * @var class-string
     */
    private string $c;

    /**
     * The serialized class members.
     */
    public array $p = [];

    /**
     * @param class-string $className
     */
    public function __construct(string $className)
    {
        $this->c = $className;
    }

    public function __serialize(): array
    {
        return [$this->c, $this->p];
    }

    public function __unserialize(array $data): void
    {
        [$this->c, $this->p] = $data;
    }

    public function getClassName(): string
    {
        return $this->c;
    }

    public static function fromObject(object $source): ObjectStasis
    {
        $className = \get_class($source);
        \assert($className !== \Closure::class, "Can't serialize Closure via ObjectStasis::fromObject()");

        $rc = Reflect::getReflectionClass($className);
        \assert(!$rc->isAnonymous(), "Can't serialize anonymous classes via ObjectStasis::fromObject()");

        $frozen = new ObjectStasis($className);

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
            $objectVars = \get_object_vars($source);
            foreach ($objectVars as $name => $v) {
                if (!\array_key_exists($name, $frozen->p)) {
                    $frozen->p[$name] = &$objectVars[$name];
                }
            }
        }

        return $frozen;
    }

    public function &getInstance(): mixed
    {
        if ($this->hasInstance()) {
            return $this->getCachedInstance();
        }
        $rc = new \ReflectionClass($this->c);
        $newInstance = $rc->newInstanceWithoutConstructor();

        if (\method_exists($newInstance, '__unserialize')) {
            // Some internal classes (like ArrayObject) don't accept references in their data.
            // Create a dereferenced copy to avoid PHP's internal reference markers.
            $newInstance->__unserialize($this->deref($this->p));
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
     * Create a copy of an array with all PHP references removed.
     * This is necessary for internal classes like ArrayObject that don't accept references.
     */
    private function deref(array $arr): array
    {
        $result = [];
        foreach ($arr as $k => $v) {
            $result[$k] = \is_array($v) ? $this->deref($v) : $v;
        }
        return $result;
    }
}
