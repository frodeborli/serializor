<?php

namespace Serializor;

use Closure;

/**
 * Implementation of UnsignedSerializableClosure to be compatible with
 * Opis/Closure and Laravel/SerializableClosure.
 *
 * @package Serializor
 */
class UnsignedSerializableClosure
{

    /**
     * Singleton of the default Serializor instance which
     * does not validate or apply signatures.
     *
     * @var null|Codec
     */
    private static ?Codec $serializor = null;

    /**
     * The closure's serializable.
     */
    protected $closure;

    /**
     * Creates a new serializable closure instance.
     *
     * @param  Closure  $closure
     * @return void
     */
    public function __construct(Closure $closure)
    {
        $this->closure = $closure;
    }

    /**
     * Resolve the closure with the given arguments.
     *
     * @return mixed
     */
    public function __invoke()
    {
        return call_user_func_array($this->closure, func_get_args());
    }

    /**
     * Gets the closure.
     *
     * @return \Closure
     */
    public function getClosure()
    {
        return $this->closure;
    }

    /**
     * Get the serializable representation of the closure.
     *
     * @return array
     */
    public function __serialize()
    {
        return [
            '_' => self::getSerializor()->_serialize($this->closure),
        ];
    }

    /**
     * Restore the closure after serialization.
     *
     * @param  array  $data
     * @return void
     */
    public function __unserialize($data)
    {
        $this->closure = self::getSerializor()->_unserialize($data['_']);
    }

    /**
     * Returns a default instance of Serializor which does not check
     * or add signatures to the serialized strings.
     *
     * @return Codec
     */
    public static function getSerializor(): Codec {
        if (self::$serializor === null) {
            self::$serializor = new Codec('');
        }
        return self::$serializor;
    }
}
