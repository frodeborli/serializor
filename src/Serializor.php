<?php

declare(strict_types=1);

namespace Serializor;

use Closure;
use Serializor\Codec;
use Serializor\CodeExtractors\AnonymousClassCodeExtractor;
use Serializor\SecretGenerators\SecretGenerationException;
use Serializor\SecretGenerators\SecretGeneratorFactory;
use Serializor\Transformers\AnonymousClassTransformer;
use Serializor\Transformers\ClosureTransformer;

use function sys_get_temp_dir;

use const DIRECTORY_SEPARATOR;
use const PHP_OS_FAMILY;

/**
 * Serializor class responsible for serializing and deserializing data,
 * particularly closures and anonymous classes. This class allows for the
 * serialization of tasks across processes, using a machine-specific secret
 * to enhance security and consistency in serialization.
 */
final class Serializor
{
    /**
     * Singleton instance for the default Serializor codec.
     *
     * This is used by static methods such as:
     * - {@see Serializor::serialize()}
     * - {@see Serializor::unserialize()}
     */
    private static ?Codec $singleton = null;

    /** @var string|null The default secret key used for serialization security */
    private static ?string $defaultSecret = null;

    /** @var array Default transformers for serializing closures and anonymous classes */
    private static array $defaultTransformers = [];

    /** @var Closure|null Custom function for transforming variables used in closures */
    private static ?Closure $transformUseVarsFunc = null;

    /** @var Closure|null Custom function for resolving variables used in closures */
    private static ?Closure $resolveUseVarsFunc = null;

    /**
     * Serializes the given value using the default Serializor instance.
     * This method acts as a replacement for PHP's native `serialize()` function.
     *
     * @param mixed $value The value to be serialized
     *
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
     *
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
            self::$singleton = new Codec();
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
        self::updateSingleton();
    }

    /**
     * Sets a custom closure to transform variables used in closures.
     *
     * @param Closure|null $transformUseVarsFunc The custom transformation function
     */
    public static function setTransformUseVarsFunc(?Closure $transformUseVarsFunc = null): void
    {
        self::$transformUseVarsFunc = $transformUseVarsFunc;
        self::updateSingleton();
    }

    /**
     * Sets a custom closure to resolve variables used in closures.
     *
     * @param Closure|null $resolveUseVarsFunc The custom resolution function
     */
    public static function setResolveUseVarsFunc(?Closure $resolveUseVarsFunc = null): void
    {
        self::$resolveUseVarsFunc = $resolveUseVarsFunc;
        self::updateSingleton();
    }

    /**
     * Returns the default set of transformers used for serializing closures
     * and anonymous classes.
     *
     * @return array The default transformers
     */
    public static function getDefaultTransformers(): array
    {
        if (!empty(self::$defaultTransformers)) {
            return self::$defaultTransformers;
        }

        return [
            new ClosureTransformer(self::$transformUseVarsFunc, self::$resolveUseVarsFunc),
            new AnonymousClassTransformer(new AnonymousClassCodeExtractor()),
        ];
    }

    /**
     * Updates the singleton Codec instance with the latest settings, such as
     * the default secret key or custom variable transformation functions.
     */
    private static function updateSingleton(): void
    {
        self::$singleton = new Codec(self::$defaultSecret);
    }

    /**
     * Automatically identifies a machine-specific secret string.
     * This secret is intended to be unique to the machine and persistent across reboots.
     *
     * @return string A machine-specific secret key
     * @throws SecretGenerationException If no suitable secret could be generated
     */
    public static function getMachineSecret(): string
    {
        $factory = new SecretGeneratorFactory(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'machine-secret');
        try {
            return $factory->create(PHP_OS_FAMILY)->generate();
        } catch (SecretGenerationException) {
            return $factory->create('fallback')->generate();
        }
    }
}
