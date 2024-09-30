<?php

declare(strict_types=1);

namespace Serializor\Transformers;

use Serializor\Stasis;

/**
 * Interface for classes that provide customized serialization for
 * Serializor.
 *
 * @package Serializor
 */
interface Transformer
{
    /**
     * Return true if this transformer can serialize the
     * value.
     *
     * @param mixed $value
     */
    public function transforms(mixed $value): bool;

    /**
     * Returns true if the transformer kan resolve the
     * value.
     *
     * @param Stasis $value
     * @return bool
     */
    public function resolves(Stasis $value): bool;

    /**
     * Should ignore the provided value and return false, or transform the
     * value and return true. The function should also invoke the provided
     * $walker function on any nested values in the transformed value.
     */
    public function transform(mixed $value): mixed;

    /**
     * Should ignore the provided value and return false if the value is
     * not supported. It should transform the value back to the representation
     * that can't be serialized and return true. The $walker function can be
     * used to "untransform" nested values first.
     */
    public function resolve(mixed $value): mixed;
}
