<?php

declare(strict_types=1);

namespace Serializor\Transformers;

use Closure;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use Serializor\ClosureStream;
use Serializor\CodeExtractors\CodeExtractor;
use Serializor\Reflect;
use Serializor\SerializerError;
use Serializor\Stasis;
use Serializor\TransformerInterface;
use WeakMap;

use function class_exists;
use function file_get_contents;
use function function_exists;
use function get_debug_type;
use function hash;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;
use function preg_match;

/**
 * Provides serialization of Closures for Serializor.
 *
 * @package Serializor
 */
final class ClosureTransformer implements TransformerInterface
{
    /** @var array<string, Closure(array, ?object, ?string):Closure> $codeMakers */
    private static array $codeMakers = [];

    /** @var ?WeakMap<Closure|Stasis,Closure|Stasis> $transformedObjects */
    private static ?WeakMap $transformedObjects;

    /** @var Closure(array<string, mixed>):array<string, mixed> $transformUseVariablesFunc */
    private Closure $transformUseVariablesFunc;

    /** @var Closure(array<string, mixed>):array<string, mixed> $resolveUseVariablesFunc */
    private Closure $resolveUseVariablesFunc;

    /**
     * @param ?Closure(array<string, mixed>):array<string, mixed> $transformUseVariablesFunc 
     * @param ?Closure(array<string, mixed>):array<string, mixed> $resolveUseVariablesFunc 
     */
    public function __construct(
        private CodeExtractor $codeExtractor,
        ?Closure $transformUseVariablesFunc = null,
        ?Closure $resolveUseVariablesFunc = null,
    ) {
        /** @var WeakMap<Closure|Stasis,Closure|Stasis>  */
        self::$transformedObjects ??= new WeakMap();

        /** @var Closure(array<string, mixed>):array<string, mixed> */
        $this->transformUseVariablesFunc = $transformUseVariablesFunc
            ?? static fn(array $useVariables): array => $useVariables;

        /** @var Closure(array<string, mixed>):array<string, mixed> */
        $this->resolveUseVariablesFunc = $resolveUseVariablesFunc
            ?? static fn(array $useVariables): array => $useVariables;
    }

    public function transforms(mixed $value): bool
    {
        return $value instanceof Closure;
    }

    public function resolves(Stasis $value): bool
    {
        return $value->getClassName() === Closure::class;
    }

    public function transform(mixed $value): mixed
    {
        if (!($value instanceof Closure)) {
            return false;
        }

        if (isset(self::$transformedObjects[$value])) {
            return self::$transformedObjects[$value];
        }

        $reflectionFunction = new ReflectionFunction($value);
        $frozen = new Stasis(Closure::class);

        $closureThis = $reflectionFunction->getClosureThis();
        $closureScopeClass = $reflectionFunction->getClosureScopeClass();
        $closureCalledClass = $reflectionFunction->getClosureCalledClass();

        $frozen->p['name'] = $reflectionFunction->getName();
        $frozen->p['hash'] = Reflect::getHash($reflectionFunction);
        if ($closureThis) {
            $frozen->p['callable'] = [$closureThis, $reflectionFunction->getName()];
        } elseif ($closureCalledClass) {
            $frozen->p['callable'] = [$closureCalledClass->getName(), $reflectionFunction->getName()];
        } elseif (function_exists($reflectionFunction->getName())) {
            $frozen->p['callable'] = $reflectionFunction->getName();
        } else {
            $frozen->p['callable'] = null;
        }
        $frozen->p['this'] = $closureThis;
        $frozen->p['scope_class'] = $closureScopeClass?->getName();
        $frozen->p['called_class'] = $closureCalledClass?->getName();
        $frozen->p['namespace'] = $reflectionFunction->getNamespaceName();

        /**
         * We can't serialize the code of native functions
         */
        if ($reflectionFunction->isInternal()) {
            self::$transformedObjects[$value] = $frozen;
            self::$transformedObjects[$frozen] = $value;
            return $frozen;
        }

        /**
         * Static functions declared on classes or functions declared on a class
         * is not serialized with source code.
         */
        if ($closureThis !== null) {
            $rc = Reflect::getReflectionClass($closureThis);
            if (self::resolveMethod($rc, $reflectionFunction->getName())) {
                self::$transformedObjects[$value] = $frozen;
                self::$transformedObjects[$frozen] = $value;
                return $frozen;
            }
        }
        if ($closureCalledClass !== null) {
            $rm = self::resolveMethod($closureCalledClass, $reflectionFunction->getName());
            if ($rm && $rm->isStatic()) {
                $frozen->p['callable'] = [$closureCalledClass->getName(), $reflectionFunction->getName()];
                self::$transformedObjects[$value] = $frozen;
                self::$transformedObjects[$frozen] = $value;
                return $frozen;
            }
        }

        self::$transformedObjects[$value] = $frozen;
        self::$transformedObjects[$frozen] = $value;

        $frozen->p['use'] = ($this->transformUseVariablesFunc)($reflectionFunction->getClosureUsedVariables());

        $code = $this->codeExtractor->extract($reflectionFunction, [], file_get_contents($reflectionFunction->getFileName()));

        $frozen->p['code'] = $code;
        if (!preg_match('/\$this(?:\W|$)/', $code)) {
            $frozen->p['this'] = null;
        }
        $frozen->p['is_static_function'] = (bool) preg_match('/(?:\W|^)(?:self|static|parent)(?:\W|$)/', $code);
        return $frozen;
    }

    public function resolve(mixed $value): mixed
    {
        if (!($value instanceof Stasis) || $value->getClassName() !== Closure::class) {
            throw new SerializerError("Can't resolve " . get_debug_type($value));
        }

        if (isset(self::$transformedObjects[$value])) {
            return self::$transformedObjects[$value];
        }


        /** @var array{object|string, string}|string $value->p['callable'] */
        if (is_callable($value->p['callable'])) {
            $result = Closure::fromCallable($value->p['callable']);
            self::$transformedObjects[$value] = $result;
            self::$transformedObjects[$result] = $value;
            return $result;
        } elseif (is_array($value->p['callable']) && is_string($value->p['callable'][0]) && class_exists($value->p['callable'][0])) {
            $callable = self::resolveCallable($value->p['callable']);
            if ($callable) {
                self::$transformedObjects[$value] = $callable;
                self::$transformedObjects[$callable] = $value;
                return $callable;
            }
        }

        $code = <<<PHP
            namespace {$value->p['namespace']} {
                return static function(array &\$useVars, ?object \$thisObject, ?string \$scopeClass): \Closure {
                    extract(\$useVars, \EXTR_OVERWRITE | \EXTR_REFS);
                    return \Closure::bind({$value->p['code']}, \$thisObject, \$scopeClass);
                };
            }
            PHP;

        $hash = hash('sha256', $code);
        if (!isset(self::$codeMakers[$hash])) {
            ClosureStream::register();
            /** @var Closure(array, ?object, ?string):Closure */
            self::$codeMakers[$hash] = require(ClosureStream::PROTOCOL . '://' . $code);
        }

        /**
         * @var array<string, mixed> $value->p['use']
         * @var ?object $value->p['this']
         * @var ?string $value->p['scope_class']
         */
        $use = ($this->resolveUseVariablesFunc)($value->p['use']);
        $result = self::$codeMakers[$hash]($use, $value->p['this'], $value->p['scope_class']);

        self::$transformedObjects[$value] = $result;
        self::$transformedObjects[$result] = $value;

        return $result;
    }

    /** @param array{object|string, string}|string $callable */
    private static function resolveCallable(array|string $callable): ?Closure
    {
        if (is_callable($callable)) {
            return Closure::fromCallable($callable);
        }
        if (is_array($callable) && (is_object($callable[0]) || class_exists($callable[0]))) {
            $rc = Reflect::getReflectionClass($callable[0]);
            $rm = self::resolveMethod($rc, $callable[1]);
            if ($rm) {
                if (!$rm->isStatic() && is_object($callable[0])) {
                    return $rm->getClosure($callable[0]);
                } else {
                    return $rm->getClosure();
                }
            }
        }
        return null;
    }

    private static function resolveMethod(ReflectionClass $rc, string $methodName): ?ReflectionMethod
    {
        $crc = $rc;
        do {
            if ($crc->hasMethod($methodName)) {
                return $crc->getMethod($methodName);
            }
        } while ($crc = $crc->getParentClass());
        return null;
    }
}
