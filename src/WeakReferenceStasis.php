<?php

declare(strict_types=1);

namespace Serializor;

use WeakReference;

/**
 * Stasis for WeakReference objects.
 * Preserves weak reference semantics - if the referenced object isn't
 * strongly referenced elsewhere, the reference becomes dead.
 */
final class WeakReferenceStasis extends Stasis
{
    private ?object $ref = null;
    private bool $dead = false;

    private function __construct() {}

    public function __serialize(): array
    {
        if ($this->dead) {
            return ['d' => true];
        }
        return ['r' => $this->ref];
    }

    public function __unserialize(array $data): void
    {
        $this->dead = $data['d'] ?? false;
        $this->ref = $data['r'] ?? null;
    }

    public function getClassName(): string
    {
        return WeakReference::class;
    }

    public static function fromWeakReference(WeakReference $value): WeakReferenceStasis
    {
        $frozen = new WeakReferenceStasis();
        $frozen->ref = $value->get();
        return $frozen;
    }

    /**
     * Mark this weak reference as dead (target not strongly referenced).
     */
    public function markDead(): void
    {
        $this->dead = true;
    }

    /**
     * Get the referenced object for strong reference tracking.
     */
    public function getRef(): ?object
    {
        return $this->ref;
    }

    public function &getInstance(): mixed
    {
        if ($this->hasInstance()) {
            return $this->getCachedInstance();
        }

        if ($this->dead || !\is_object($this->ref)) {
            // Create a dead WeakReference
            $temp = new \stdClass();
            $result = WeakReference::create($temp);
            unset($temp);
        } else {
            $result = WeakReference::create($this->ref);
        }

        $this->setInstance($result);
        return $result;
    }
}
