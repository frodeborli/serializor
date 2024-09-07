<?php

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
    public mixed $val;
    public array $shortcuts;

    public function __construct(array $val, array $shortcuts = [])
    {
        $this->val = &$val[0];
        $this->shortcuts = $shortcuts;
    }
}
