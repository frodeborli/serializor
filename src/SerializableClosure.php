<?php
namespace Serializor;

use Closure;
use Codec;

/**
 * Implementation of SerializableClosure to be compatible with
 * Opis/Closure and Laravel/SerializableClosure.
 *
 * @package Serializor
 */
class SerializableClosure {

    private Closure $closure;

    public function __construct(Closure $closure) {
        $this->closure = $closure;
    }

    /**
     * Resolve the closure with the given arguments.
     *
     * @return mixed
     */
    public function __invoke() {
        return call_user_func_array($this->closure, func_get_args());
    }

    /**
     * Get the Closure object.
     *
     * @return Closure
     */
    public function getClosure(): Closure {
        return $this->closure;
    }

    /**
     * Create a new unsigned serializable closure instance.
     *
     * @param  Closure  $closure
     * @return UnsignedSerializableClosure
     */
    public static function unsigned(Closure $closure)
    {
        return new UnsignedSerializableClosure($closure);
    }

    /**
     * Hook into PHP serialization
     *
     * @return array
     */
    public function __serialize()
    {
        return ['_' => Codec::getInstance()->_serialize($this->closure)];
    }

    /**
     * Hook into PHP unserialization
     *
     * @param array $data
     * @return void
     */
    public function __unserialize(array $data): void
    {
        $this->closure = Codec::getInstance()->_unserialize($data['_']);
    }

    public static function resolveUseVariablesUsing(callable $resolver): void {
        Codec::setResolveUseVarsFunc($resolver instanceof Closure ? $resolver : Closure::fromCallable($resolver));
    }

    public static function transformUseVariablesUsing(callable $transformer): void {
        Codec::setTransformUseVarsFunc($transformer instanceof Closure ? $transformer : Closure::fromCallable($transformer));
    }

    /**
     * Sets the serializable closure secret key.
     *
     * @param  string|null  $secret
     * @return void
     */
    public static function setSecretKey($secret): void {
        self::$secret = $secret;

        Codec::setDefaultSecret($secret);
    }

    private static ?string $secret = null;
}
