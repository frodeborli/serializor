<?php

declare(strict_types=1);

namespace Serializor;

use Serializor\Codec;

/**
 * Serializor class responsible for serializing and deserializing data,
 * particularly closures and anonymous classes.
 */
class Serializor
{
    /**
     * Singleton instance for the default Serializor codec.
     */
    private static ?Codec $singleton = null;

    /** @var string|null The default secret key used for serialization security */
    private static ?string $defaultSecret = null;

    /**
     * Serializes the given value using the default Serializor instance.
     * This method acts as a replacement for PHP's native `serialize()` function.
     *
     * @param mixed $value The value to be serialized
     * @return string The serialized string
     */
    public static function serialize(mixed $value): string
    {
        return self::getInstance()->serialize($value);
    }

    /**
     * Unserializes the given string using the default Serializor instance.
     * This method acts as a replacement for PHP's native `unserialize()` function.
     *
     * @param string $value The serialized string to be unserialized
     * @return mixed The unserialized value
     */
    public static function &unserialize(string $value): mixed
    {
        return self::getInstance()->unserialize($value);
    }

    /**
     * Retrieves the default Codec instance. This is a singleton, meaning
     * only one instance is created and reused across multiple calls.
     *
     * @return Codec The codec instance for serializing and deserializing data
     */
    public static function getInstance(): Codec
    {
        if (self::$singleton === null) {
            self::$singleton = new Codec(self::$defaultSecret ?? '');
        }
        return self::$singleton;
    }

    /**
     * Sets a custom secret key for the default Serializor instance, which can be
     * used to secure the serialization process.
     *
     * @param string $secret The secret key to use for serialization
     */
    public static function setDefaultSecret(string $secret): void
    {
        self::$defaultSecret = $secret;
        self::$singleton = null;
    }

    /**
     * Register a custom factory for handling user-defined types.
     *
     * @param class-string $class The class name to handle
     * @param callable(object): ?Stasis $factory Factory that returns a Stasis or null to skip
     */
    public static function registerFactory(string $class, callable $factory): void
    {
        Stasis::registerFactory($class, $factory);
    }
}
