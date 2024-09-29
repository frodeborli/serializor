<?php

declare(strict_types=1);

namespace Serializor\Transformers;

use Closure;
use ParseError;
use ReflectionClass;
use ReflectionObject;
use ReflectionProperty;
use Serializor\ClosureStream;
use Serializor\CodeExtractors\CodeExtractor;
use Serializor\SerializerError;
use Serializor\Stasis;
use Serializor\TransformerInterface;

use function array_key_exists;
use function file_get_contents;
use function get_debug_type;
use function hash;
use function is_object;

/**
 * Provides serialization of anonymous classes for Serializor.
 *
 * @package Serializor
 */
final class AnonymousClassTransformer implements TransformerInterface
{
    /** @var array<string, string> $sourceCache */
    private static array $sourceCache = [];

    /** @var array<string, array{code: string}> $functionCache */
    private static array $functionCache = [];

    /** @var array<string, Closure():object> $classMakerCache */
    private static array $classMakerCache = [];

    public function __construct(
        private CodeExtractor $codeExtractor,
    ) {}

    public function transforms(mixed $value): bool
    {
        return is_object($value) && (new ReflectionClass($value))->isAnonymous();
    }

    public function resolves(Stasis $value): bool
    {
        return $value->getClassName() === 'class@anonymous';
    }

    public function transform(mixed $value): mixed
    {
        if (!$this->transforms($value)) {
            throw new SerializerError("Can't transform " . get_debug_type($value));
        }
        $ro = new ReflectionObject($value);
        $hash = $this->getClassHash($ro);

        $frozen = new Stasis('class@anonymous');
        $frozen->p['|hash'] = $hash;
        $frozen->p['|code'] = $this->getCode($ro, $hash);
        $parentRo = $ro->getParentClass();
        $frozen->p['|extends'] = $parentRo ? $parentRo->getName() : null;
        $frozen->p['|implements'] = $ro->getInterfaceNames();
        $frozen->p['|props'] = $this->getObjectProperties($ro, $value);
        return $frozen;
    }

    private function getObjectProperties(ReflectionObject $reflectionObject, object $object): array
    {
        $cro = $reflectionObject;
        $result = [];
        $prefix = '';
        do {
            foreach ($cro->getProperties() as $rp) {
                if ($rp->isStatic()) {
                    continue;
                }
                if ($rp->isInitialized($object)) {
                    $result[$prefix . $rp->getName()] = $rp->getValue($object);
                }
            }
            $cro = $cro->getParentClass();
            if ($cro !== false) {
                $prefix = $cro->getName() . "\0";
            }
        } while ($cro !== false);

        return $result;
    }

    public function resolve(mixed $value): mixed
    {
        if (!($value instanceof Stasis) || $value->getClassName() !== 'class@anonymous') {
            throw new SerializerError("Can't transform " . get_debug_type($value));
        }

        $hash = $value->p['|hash'];

        if (!isset(self::$classMakerCache[$hash])) {
            $code = 'return static function() {
                return new ' . $value->p['|code'] . ';
            };';
            // FIXME: CHeck if `ClosureStream` should be unregistered after use
            ClosureStream::register();
            try {
                self::$classMakerCache[$hash] = require(ClosureStream::PROTOCOL . '://' . $code);
            } catch (ParseError) {
                throw new SerializerError('Could not parse stasis to anonymous class');
            }
        }

        $instance = self::$classMakerCache[$hash]();

        $this->setObjectProperties($instance, $value->p['|props']);

        return $instance;
    }

    private function setObjectProperties(object $value, array $properties): void
    {
        $reflectionObject = new ReflectionObject($value);
        $prefix = '';
        do {
            Closure::bind(function () use ($value, $reflectionObject, $properties, $prefix) {
                foreach ($reflectionObject->getProperties() as $reflectionProperty) {
                    if ($reflectionProperty->isStatic()) {
                        continue;
                    }
                    $name = $prefix . $reflectionProperty->getName();
                    if (array_key_exists($name, $properties)) {
                        $reflectionProperty->setValue($value, $properties[$name]);
                    }
                }
            }, $value, $reflectionObject->getName())();

            $reflectionObject = $reflectionObject->getParentClass();
            if ($reflectionObject !== false) {
                $prefix = $reflectionObject->getName() . "\0";
            }
        } while ($reflectionObject !== false);
    }

    private function getCode(ReflectionObject $ro, string $hash, array $discardMembers = ['__construct']): string
    {
        if (isset(self::$functionCache[$hash])) {
            return self::$functionCache[$hash]['code'];
        }

        $sourceFile = $ro->getFileName();
        $source = self::$sourceCache[$sourceFile]
            ??= file_get_contents($sourceFile);

        self::$functionCache[$hash] = [
            'code' => $this->codeExtractor->extract($ro, $discardMembers, $source),
        ];

        return self::$functionCache[$hash]['code'];
    }

    private function getClassHash(ReflectionObject $ro): string
    {
        $pco = $ro->getParentClass();
        $hash = ($ro->getDocComment() ?: '')
            . ($ro->getFileName() ?: '')
            . ($ro->getStartLine() ?: '')
            . ($ro->getEndLine() ?: '')
            . ($ro->getName())
            . ($ro->getShortName())
            . ($pco ? $pco->getName() : '');
        return hash('sha256', $hash);
    }
}
