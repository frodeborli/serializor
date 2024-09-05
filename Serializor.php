<?php

use Serializor\Codec;
use Serializor\SerializerError;
use Serializor\Transformers\AnonymousClassTransformer;
use Serializor\Transformers\ClosureTransformer;

/**
 * Serializor class responsible for serializing and deserializing data,
 * particularly closures and anonymous classes. This class allows for the
 * serialization of tasks across processes, using a machine-specific secret
 * to enhance security and consistency in serialization.
 */
class Serializor
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
     * List of files used to generate a machine-specific secret key.
     * The library attempts to generate a secret based on these files,
     * making it possible to serialize and unserialize data on the same
     * machine, while adding protection from deserialization on different
     * machines.
     */
    public const AUTOSECRET_FILES = [
        'primary' => [
            '/var/lib/dbus',
            '/etc/machine-id',
            '/proc/sys/kernel/random/boot_id',
            '/sys/class/dmi/id/product_uuid',
            '/etc/hostconfig',
            '/etc/hostid',
            '/etc/rc.conf',
            '/var/db/hostid',
            'C:\ProgramData\Microsoft\Windows\DeviceMetadataCache\dmrc.idx',
            'C:\Windows\System32\spp\store\2.0\tokens.dat',
            'C:\Windows\System32\drivers\etc\hosts',
            '/etc/hosts',
        ],
        'secondary' => [
            '/proc/cpuinfo',
            'C:\Windows\System32\license.rtf',
            '/Library/Preferences/com.apple.TimeMachine.plist',
        ],
    ];

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
            new AnonymousClassTransformer(),
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
     * Automatically identifies a machine-specific secret string by examining
     * a combination of system files. This secret is intended to be unique to the
     * machine and persistent across reboots.
     *
     * @return string A machine-specific secret key
     * @throws SerializerError If no suitable secret could be generated
     */
    public static function getMachineSecret(): string
    {
        $primary = null;
        $secondary = null;

        foreach (self::AUTOSECRET_FILES['primary'] as $candidate) {
            if ($primary = self::getSecretFromPath($candidate)) {
                break;
            }
        }

        foreach (self::AUTOSECRET_FILES['secondary'] as $candidate) {
            if ($secondary = self::getSecretFromPath($candidate)) {
                break;
            }
        }

        if ($primary !== null && $secondary !== null) {
            return md5(serialize([$primary, $secondary]));
        }

        throw new SerializerError('Unable to obtain a machine-specific secret');
    }

    /**
     * Generates a secret string based on the metadata and contents of the given file.
     *
     * @param string $path The file path to use for generating the secret
     *
     * @return string|null The generated secret string, or null if the file is not readable
     */
    private static function getSecretFromPath(string $path): ?string
    {
        if (!is_file($path) || !\is_readable($path)) {
            return null;
        }

        try {
            $fp = fopen($path, 'rb');
            $data = fread($fp, 65536); // Read up to 64KB of file content
            $stat = fstat($fp); // Get file metadata
            $data .= serialize($stat); // Append serialized file metadata
            fclose($fp);

            return $data;
        } catch (Throwable) {
            return null;
        }
    }
}
