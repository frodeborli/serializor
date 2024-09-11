<?php

declare(strict_types=1);

namespace Serializor;

use LogicException;
use ReflectionReference;
use Serializor\Box;
use Serializor\SerializerError;
use Serializor\Serializor;
use Serializor\Stasis;
use Serializor\TransformerInterface;
use Throwable;
use WeakMap;

/**
 * Serializor provides a powerful way to serialize PHP values and for
 * customizing the serialization and unserialization process via
 * implementations of the TransformerInterface.
 */
class Codec
{

    /**
     * Transformer implementations are used to serialize classes
     * whenever normal serialization fails.
     *
     * @var TransformerInterface[]
     */
    private array $transformers = [];

    /**
     * The secret key used to sign serialized values and validate
     * the signatures before unserialization. The secret should be
     * provided via the constructor if possible.
     */
    private string $secret;

    /**
     * Tracks the original value of a reference, for comparison and
     * detection of bugs in the serialization process.
     *
     * @var array<string,mixed>
     */
    private array $referenceSources = [];

    /**
     * Tracks the new value of a reference so that the new value is
     * correctly reused in future references.
     *
     * @var array<string,mixed>
     */
    private array $referenceTargets = [];

    /**
     * Callbacks that should be invoked when a reference has been completely
     * restored/transformed - to handle recursive serialization.
     *
     * @var array<string,Closure[]>
     */
    private array $referenceCallbacks = [];

    private array $shortcuts = [];

    /**
     * @var WeakMap<object,object>
     */
    private WeakMap $encodedObjects;

    /**
     * @param string|null $secret       A string secret which is shared among applications serializing and unserializing
     * @param array|null  $transformers Custom set of transformers (overrides the default transformers)
     *
     * @return void
     *
     * @throws SerializerError if unable to automatically detect a secret
     */
    public function __construct(?string $secret = null, ?array $transformers = null)
    {
        if ($secret !== null) {
            $this->secret = $secret;
        } else {
            $this->secret = Serializor::getMachineSecret();
        }
        foreach ($transformers ?? Serializor::getDefaultTransformers() as $transformer) {
            $this->addTransformer($transformer);
        }
        $this->encodedObjects = new WeakMap();
    }

    /**
     * Add a custom transformer that will serialize and unserialize special values
     * that can't normally be serialized.
     */
    public function addTransformer(TransformerInterface $transformer): void
    {
        $this->transformers[] = $transformer;
    }

    /**
     * Get the transformer instance that is willing to serialize the
     * value.
     *
     * @var class-string<mixed>|mixed
     */
    private function getTransformer(mixed $value): ?TransformerInterface
    {
        foreach ($this->transformers as $transformer) {
            if ($transformer->transforms($value)) {
                return $transformer;
            }
        }

        return null;
    }

    private function getResolver(Stasis $value): ?TransformerInterface
    {
        foreach ($this->transformers as $transformer) {
            if ($transformer->resolves($value)) {
                return $transformer;
            }
        }

        return null;
    }

    /**
     * Perform serialization of a value.
     */
    public function serialize(mixed &$value): string
    {
        try {
            $this->encodedObjects = new WeakMap();
            $this->referenceSources = [];
            $this->referenceTargets = [];
            $this->referenceCallbacks = [];
            $this->shortcuts = [];
            $result = \serialize($value);
        } catch (Throwable $e) {
            $v = [&$value];
            $result = $this->transform($v, [], null);
            $result = \serialize(new Box($result, $this->shortcuts));
        } finally {
            $this->referenceSources = [];
            $this->referenceTargets = [];
            $this->referenceCallbacks = [];
            $this->shortcuts = [];
        }

        if ($this->secret !== '') {
            $signature = \hash_hmac('sha256', $result, $this->secret, false);

            return $signature . '|' . $result;
        }

        return $result;
    }

    /**
     * Encodes an object structure into a corresponding object structure where
     * values that can't be serialized are converted to Stasis objects.
     */
    protected function &transform(mixed &$source, array $path, string|int|null $key): mixed
    {
        if ($source === null || \is_scalar($source)) {
            throw new SerializerError('Trying to encode NULL or scalar');
        }
        if ($key !== null) {
            $path[] = $key;
        }
        $sourceWrap = [&$source];
        $referenceId = ReflectionReference::fromArrayElement($sourceWrap, 0)->getId();

        if (isset($this->referenceSources[$referenceId])) {
            if ($this->referenceSources[$referenceId][0] === $sourceWrap[0]) {
                // This reference definitely targets the same value
                return $this->referenceTargets[$referenceId];
            } else {
                throw new LogicException('The source value has changed during serialization, and this is a fatal problem');
            }
        }

        $this->referenceSources[$referenceId] = &$sourceWrap;

        // Objects can also be found via the WeakMap
        if (\is_object($source) && isset($this->encodedObjects[$source])) {
            $result = $this->encodedObjects[$source];
            $this->referenceTargets[$referenceId] = &$result;

            return $result;
        }

        // Walk arrays recursively
        if (\is_array($source)) {
            /**
             * Proceed with creating a new array.
             */
            $result = [];
            foreach ($source as $k => &$v) {
                if (\is_scalar($v) || $v === null) {
                    $result[$k] = &$v;
                } else {
                    $result[$k] = &$this->transform($source[$k], $path, $k);
                }
            }
            $this->referenceTargets[$referenceId] = &$result;

            return $this->referenceTargets[$referenceId];
        }

        // Remaining objects are passed as is if they are serializable
        try {
            serialize($source);
            /**
             * If an exception was not thrown during serialization, it indicates that
             * PHP can serialize this value without additional recursive logic.
             */
            $target = $source;
            if (\is_object($source)) {
                $this->encodedObjects[$source] = $target;
            }
            $this->referenceTargets[$referenceId] = &$target;

            return $target;
        } catch (Throwable $e) {
            /*
             * This value can't be serialized using native serialization, so we must use recursive approach
             */
        }

        // Try to use a transformer
        $transformer = $this->getTransformer($source);
        if ($transformer !== null) {
            /**
             * There is a transformer that claims to be able to generate
             * a Stasis object for this value.
             */
            $target = $transformer->transform($source);
            $this->shortcuts[] = &$target;
            if (\is_object($source)) {
                $this->encodedObjects[$source] = $target;
            }
            $this->referenceTargets[$referenceId] = &$target;
            $target->p = &$this->transform($target->p, $path, 'p');

            return $target;
        } else {
            /**
             * There is no transformer for the value, so we attempt
             * to directly create a Stasis instance from the target
             * object.
             */
            $target = Stasis::from($source);
            $this->shortcuts[] = &$target;
            if (\is_object($source)) {
                $this->encodedObjects[$source] = $target;
            }
            $this->referenceTargets[$referenceId] = &$target;
            $target->p = &$this->transform($target->p, $path, 'p');

            return $target;
        }
    }

    /**
     * Perform unserialization of a string.
     */
    public function &unserialize(string $value): mixed
    {
        try {
            $this->referenceSources = [];
            $this->referenceTargets = [];
            $this->referenceCallbacks = [];

            if ($this->secret !== '') {
                $signatureEndOffset = \strpos($value, '|') ?: 0;
                $signature = \substr($value, 0, $signatureEndOffset);
                $value = \substr($value, $signatureEndOffset + 1);
                if ($signature !== \hash_hmac('sha256', $value, $this->secret, false)) {
                    throw new SerializerError('Invalid signature in the serialized data');
                }
            }

            $result = unserialize($value);

            if ($result instanceof Box) {
                foreach ($result->shortcuts as &$shortcut) {
                    if ($shortcut instanceof Stasis) {
                        $this->resolve($shortcut);
                    }
                }
                return $result->val;
            }

            return $result;
        } finally {
            $this->referenceSources = [];
            $this->referenceTargets = [];
            $this->referenceCallbacks = [];
        }
    }

    private function resolve(array|Stasis &$source): void
    {
        $sourceWrap = [&$source];
        $referenceId = ReflectionReference::fromArrayElement($sourceWrap, 0)->getId();
        if (isset($this->referenceCallbacks[$referenceId]) || \array_key_exists($referenceId, $this->referenceCallbacks)) {
            // Already being resolved
            $this->referenceCallbacks[$referenceId][] = static function (mixed &$target) use (&$source) {
                $source = $target;
            };

            return;
        }
        $this->referenceCallbacks[$referenceId] = [];

        if (\is_array($source)) {
            foreach ($source as &$v) {
                if (\is_array($v) || $v instanceof Stasis) {
                    $this->resolve($v);
                }
            }
        } elseif ($source->hasInstance()) {
            $source = $source->getInstance();
        } else {

            $this->resolve($source->p);
            $resolver = $this->getResolver($source);
            if ($resolver) {
                $source->setInstance($source = $resolver->resolve($source));
            } else {
                $source = $source->getInstance();
            }
            if (!empty($this->referenceCallbacks[$referenceId])) {
                foreach ($this->referenceCallbacks[$referenceId] as $cb) {
                    $cb($source);
                }
            }
            unset($this->referenceCallbacks[$referenceId]);
        }
    }
}
