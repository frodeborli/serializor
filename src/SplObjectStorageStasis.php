<?php

declare(strict_types=1);

namespace Serializor;

use SplObjectStorage;

/**
 * Stasis for SplObjectStorage objects.
 * Uses objects as keys with associated data values.
 */
final class SplObjectStorageStasis extends Stasis
{
    private array $objects = [];
    private array $data = [];

    private function __construct() {}

    public function __serialize(): array
    {
        return ['o' => $this->objects, 'd' => $this->data];
    }

    public function __unserialize(array $data): void
    {
        $this->objects = $data['o'];
        $this->data = $data['d'];
    }

    public function getClassName(): string
    {
        return SplObjectStorage::class;
    }

    public static function fromStorage(SplObjectStorage $value): SplObjectStorageStasis
    {
        $frozen = new SplObjectStorageStasis();

        foreach ($value as $obj) {
            $frozen->objects[] = $obj;
            $frozen->data[] = $value[$obj];
        }

        return $frozen;
    }

    /**
     * Get the objects for transformation.
     */
    public function &getObjects(): array
    {
        return $this->objects;
    }

    /**
     * Get the data for transformation.
     */
    public function &getData(): array
    {
        return $this->data;
    }

    public function &getInstance(): mixed
    {
        if ($this->hasInstance()) {
            return $this->getCachedInstance();
        }

        $storage = new SplObjectStorage();

        for ($i = 0, $len = count($this->objects); $i < $len; $i++) {
            $storage[$this->objects[$i]] = $this->data[$i];
        }

        $this->setInstance($storage);
        return $storage;
    }
}
