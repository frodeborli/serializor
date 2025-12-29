<?php

declare(strict_types=1);

namespace Serializor\Transformers;

use Serializor\Stasis;
use Serializor\TransformerInterface;
use SplObjectStorage;

/**
 * Provides serialization of SplObjectStorage for Serializor.
 *
 * SplObjectStorage uses objects as keys, which requires special handling
 * during serialization to preserve the object-to-data mappings.
 */
class SplObjectStorageTransformer implements TransformerInterface
{
    public function transforms(mixed $value): bool
    {
        return $value instanceof SplObjectStorage;
    }

    public function resolves(Stasis $value): bool
    {
        return $value->getClassName() === SplObjectStorage::class;
    }

    public function transform(mixed $value): mixed
    {
        if (!($value instanceof SplObjectStorage)) {
            return false;
        }

        $frozen = new Stasis(SplObjectStorage::class);

        // Use parallel arrays for efficiency
        $keys = [];
        $vals = [];
        foreach ($value as $obj) {
            $keys[] = $obj;
            $vals[] = $value[$obj];
        }

        $frozen->p['k'] = $keys;
        $frozen->p['v'] = $vals;

        return $frozen;
    }

    public function resolve(mixed $value): mixed
    {
        if (!($value instanceof Stasis) || $value->getClassName() !== SplObjectStorage::class) {
            return false;
        }

        $storage = new SplObjectStorage();

        $keys = $value->p['k'];
        $vals = $value->p['v'];
        for ($i = 0, $len = count($keys); $i < $len; $i++) {
            $storage[$keys[$i]] = $vals[$i];
        }

        return $storage;
    }
}
