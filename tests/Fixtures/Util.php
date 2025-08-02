<?php
namespace Tests\Fixtures;

use Serializor;

class Util {
    /**
     * Serializes and then unserializes a value, preserving its type.
     *
     * @template T
     * @param T $v The value to be serialized and unserialized
     * @return T The unserialized value, preserving the original type
     */
    public static function s(mixed $v): mixed
    {
        return Serializor::unserialize(Serializor::serialize($v));
    }
}
