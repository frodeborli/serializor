<?php

declare(strict_types=1);

namespace Serializor\Transformers;

use Serializor\Stasis;
use Serializor\TransformerInterface;
use WeakReference;

/**
 * Provides serialization of WeakReference for Serializor.
 *
 * WeakReference is a final internal class that cannot be instantiated
 * without its constructor, so we need custom handling.
 */
class WeakReferenceTransformer implements TransformerInterface
{
    public function transforms(mixed $value): bool
    {
        return $value instanceof WeakReference;
    }

    public function resolves(Stasis $value): bool
    {
        return $value->getClassName() === WeakReference::class;
    }

    public function transform(mixed $value): mixed
    {
        if (!($value instanceof WeakReference)) {
            return false;
        }

        $frozen = new Stasis(WeakReference::class);
        $frozen->p['ref'] = $value->get();

        return $frozen;
    }

    public function resolve(mixed $value): mixed
    {
        if (!($value instanceof Stasis) || $value->getClassName() !== WeakReference::class) {
            return false;
        }

        $ref = $value->p['ref'] ?? null;
        $isDead = $value->p['dead'] ?? false;

        // If marked dead or the referenced object is null/not an object, create a dead reference
        if ($isDead || !is_object($ref)) {
            // Create a WeakReference that immediately becomes dead
            $temp = new \stdClass();
            $weakRef = WeakReference::create($temp);
            unset($temp);
            return $weakRef;
        }

        return WeakReference::create($ref);
    }
}
