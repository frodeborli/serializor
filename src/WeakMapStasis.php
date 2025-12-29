<?php

declare(strict_types=1);

namespace Serializor;

use WeakMap;

/**
 * Stasis for WeakMap objects.
 * Keys are weakly referenced - entries with keys not strongly referenced
 * elsewhere are excluded during unserialization.
 */
final class WeakMapStasis extends Stasis
{
    private array $keys = [];
    private array $values = [];
    private array $dead = [];

    private function __construct() {}

    public function __serialize(): array
    {
        $data = ['k' => $this->keys, 'v' => $this->values];
        // Only include dead array if there are dead entries
        $hasDead = false;
        foreach ($this->dead as $isDead) {
            if ($isDead) {
                $hasDead = true;
                break;
            }
        }
        if ($hasDead) {
            $data['d'] = $this->dead;
        }
        return $data;
    }

    public function __unserialize(array $data): void
    {
        $this->keys = $data['k'];
        $this->values = $data['v'];
        $this->dead = $data['d'] ?? array_fill(0, count($this->keys), false);
    }

    public function getClassName(): string
    {
        return WeakMap::class;
    }

    public static function fromWeakMap(WeakMap $value): WeakMapStasis
    {
        $frozen = new WeakMapStasis();

        foreach ($value as $obj => $data) {
            $frozen->keys[] = $obj;
            $frozen->values[] = $data;
        }
        $frozen->dead = array_fill(0, count($frozen->keys), false);

        return $frozen;
    }

    /**
     * Mark an entry as dead by its key's object ID.
     */
    public function markDeadByIndex(int $index): void
    {
        $this->dead[$index] = true;
    }

    /**
     * Get the keys for strong reference tracking.
     */
    public function &getKeys(): array
    {
        return $this->keys;
    }

    /**
     * Get the values for transformation.
     */
    public function &getValues(): array
    {
        return $this->values;
    }

    /**
     * Get the dead flags array.
     */
    public function &getDead(): array
    {
        return $this->dead;
    }

    public function &getInstance(): mixed
    {
        if ($this->hasInstance()) {
            return $this->getCachedInstance();
        }

        $map = new WeakMap();

        for ($i = 0, $len = count($this->keys); $i < $len; $i++) {
            // Skip entries marked as dead
            if (!empty($this->dead[$i])) {
                continue;
            }
            // Only add if key is a valid object
            if (\is_object($this->keys[$i])) {
                $map[$this->keys[$i]] = $this->values[$i];
            }
        }

        $this->setInstance($map);
        return $map;
    }
}
