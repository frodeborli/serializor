<?php

declare(strict_types=1);

namespace Serializor;

use SplHeap;
use SplMaxHeap;
use SplMinHeap;

/**
 * Stasis for SplHeap subclasses (SplMaxHeap, SplMinHeap).
 * PHP <8.5 doesn't have __serialize() for these classes.
 */
final class SplHeapStasis extends Stasis
{
    /**
     * The heap class name.
     * @var class-string<SplHeap>
     */
    private string $c;

    /**
     * The heap items (extracted via iteration).
     * @var array<int, mixed>
     */
    public array $items = [];

    /**
     * @param class-string<SplHeap> $className
     */
    public function __construct(string $className)
    {
        $this->c = $className;
    }

    public function __serialize(): array
    {
        return [$this->c, $this->items];
    }

    public function __unserialize(array $data): void
    {
        [$this->c, $this->items] = $data;
    }

    public function getClassName(): string
    {
        return $this->c;
    }

    /**
     * Get reference to items for Codec child transformation.
     */
    public function &getItems(): array
    {
        return $this->items;
    }

    public static function fromHeap(SplHeap $heap): SplHeapStasis
    {
        $className = \get_class($heap);
        $frozen = new SplHeapStasis($className);

        // Clone to avoid destructive iteration on original
        $copy = clone $heap;
        foreach ($copy as $item) {
            $frozen->items[] = $item;
        }

        return $frozen;
    }

    public function &getInstance(): mixed
    {
        if ($this->hasInstance()) {
            return $this->getCachedInstance();
        }

        $rc = new \ReflectionClass($this->c);
        /** @var SplHeap $heap */
        $heap = $rc->newInstance();

        // Re-insert all items
        foreach ($this->items as $item) {
            $heap->insert($item);
        }

        $this->setInstance($heap);
        return $heap;
    }
}
