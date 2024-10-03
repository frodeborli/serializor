<?php

declare(strict_types=1);

namespace Serializor;

/**
 * This class is used to indicate that the unserialized data
 * needs special treatment in order to correctly restore the
 * state after unserialization.
 *
 * @package Serializor
 */
final class Box
{
    public function __construct(
        public mixed $value,
        public array $shortcuts = [],
    ) {}
}
