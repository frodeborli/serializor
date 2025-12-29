<?php

declare(strict_types=1);

namespace Serializor\Transformers;

use Serializor\Stasis;
use Serializor\TransformerInterface;
use WeakMap;

/**
 * Provides serialization of WeakMap for Serializor.
 *
 * WeakMap uses objects as keys with weak reference semantics. Keys that
 * aren't strongly referenced elsewhere in the serialized data will be
 * marked as dead and excluded during unserialization.
 */
class WeakMapTransformer implements TransformerInterface
{
    public function transforms(mixed $value): bool
    {
        return $value instanceof WeakMap;
    }

    public function resolves(Stasis $value): bool
    {
        return $value->getClassName() === WeakMap::class;
    }

    public function transform(mixed $value): mixed
    {
        if (!($value instanceof WeakMap)) {
            return false;
        }

        $frozen = new Stasis(WeakMap::class);

        // Use parallel arrays for keys, values, and dead flags
        $keys = [];
        $vals = [];
        foreach ($value as $obj => $data) {
            $keys[] = $obj;
            $vals[] = $data;
        }

        $frozen->p['k'] = $keys;
        $frozen->p['v'] = $vals;
        // Dead flags will be set by Codec::markDeadWeakReferences()
        $frozen->p['dead'] = array_fill(0, count($keys), false);

        return $frozen;
    }

    public function resolve(mixed $value): mixed
    {
        if (!($value instanceof Stasis) || $value->getClassName() !== WeakMap::class) {
            return false;
        }

        $map = new WeakMap();

        $keys = $value->p['k'] ?? [];
        $vals = $value->p['v'] ?? [];
        $dead = $value->p['dead'] ?? [];

        for ($i = 0, $len = count($keys); $i < $len; $i++) {
            // Skip entries marked as dead (key not strongly referenced elsewhere)
            if (!empty($dead[$i])) {
                continue;
            }
            // Only add if key is a valid object
            if (is_object($keys[$i])) {
                $map[$keys[$i]] = $vals[$i];
            }
        }

        return $map;
    }
}
