<?php

declare(strict_types=1);

namespace Serializor;

use SplPriorityQueue;

/**
 * Stasis for SplPriorityQueue.
 * PHP <8.5 doesn't have __serialize() for this class.
 */
final class SplPriorityQueueStasis extends Stasis
{
    /**
     * The class name.
     * @var class-string<SplPriorityQueue>
     */
    private string $c;

    /**
     * The queue items with their priorities.
     * Each item is ['data' => mixed, 'priority' => mixed]
     * @var array<int, array{data: mixed, priority: mixed}>
     */
    public array $items = [];

    /**
     * @param class-string<SplPriorityQueue> $className
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

    public static function fromQueue(SplPriorityQueue $queue): SplPriorityQueueStasis
    {
        $className = \get_class($queue);
        $frozen = new SplPriorityQueueStasis($className);

        // Clone to avoid destructive iteration on original
        $copy = clone $queue;
        $copy->setExtractFlags(SplPriorityQueue::EXTR_BOTH);

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
        /** @var SplPriorityQueue $queue */
        $queue = $rc->newInstance();

        // Re-insert all items with their priorities
        foreach ($this->items as $item) {
            $queue->insert($item['data'], $item['priority']);
        }

        $this->setInstance($queue);
        return $queue;
    }
}
