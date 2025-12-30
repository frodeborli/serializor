<?php

declare(strict_types=1);

namespace Serializor;

use SplFixedArray;

/**
 * Stasis for SplFixedArray on PHP versions that lack __serialize()/__unserialize().
 * PHP 8.2+ has native support; this handles PHP 8.1.
 */
final class SplFixedArrayStasis extends Stasis
{
    /**
     * The array size.
     */
    private int $s;

    /**
     * The array elements.
     */
    public array $e = [];

    public function __construct(int $size)
    {
        $this->s = $size;
    }

    public function __serialize(): array
    {
        return [$this->s, $this->e];
    }

    public function __unserialize(array $data): void
    {
        [$this->s, $this->e] = $data;
    }

    public function getClassName(): string
    {
        return SplFixedArray::class;
    }

    /**
     * Get reference to elements array for transformation.
     */
    public function &getElements(): array
    {
        return $this->e;
    }

    public static function fromArray(SplFixedArray $source): self
    {
        $frozen = new self($source->getSize());
        foreach ($source as $i => $v) {
            $frozen->e[$i] = $v;
        }
        return $frozen;
    }

    public function &getInstance(): mixed
    {
        if ($this->hasInstance()) {
            return $this->getCachedInstance();
        }

        $arr = new SplFixedArray($this->s);
        foreach ($this->e as $i => $v) {
            if ($v instanceof Stasis) {
                if ($v->hasInstance()) {
                    $arr[$i] = $v->getInstance();
                } else {
                    $v->whenResolved(function ($instance) use ($arr, $i) {
                        $arr[$i] = $instance;
                    });
                }
            } else {
                $arr[$i] = $v;
            }
        }

        $this->setInstance($arr);
        return $arr;
    }
}
