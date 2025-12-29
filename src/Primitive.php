<?php

declare(strict_types=1);

namespace Serializor;

/**
 * Base class for lightweight serializable primitives that don't need
 * the full Stasis transformation pipeline.
 */
abstract class Primitive
{
    /**
     * Restore the original value from this primitive.
     */
    abstract public function instantiate(): mixed;
}
