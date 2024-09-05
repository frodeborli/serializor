<?php

namespace Serializor\Transformers;

use Closure;
use LogicException;
use PhpToken;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use RuntimeException;
use Codec;
use Serializor\ClosureStream;
use Serializor\Debug;
use Serializor\Reflect;
use Serializor\SerializerError;
use Serializor\Stasis;
use Serializor\TransformerInterface;
use WeakMap;

/**
 * Provides serialization of Closures for Serializor.
 *
 * @package Serializor
 */
class ClosureTransformer implements TransformerInterface
{
    private static array $codeMakers = [];
    private static array $tokenCache = [];
    private static array $functionCache = [];
    /**
     * @var null|WeakMap<Closure|Stasis,Closure|Stasis>
     */
    private static ?WeakMap $transformedObjects = null;
    private ?array $tmp = null;
    private ?Closure $transformUseVariablesFunc;
    private ?Closure $resolveUseVariablesFunc;

    public function __construct(?Closure $transformUseVariablesFunc = null, ?Closure $resolveUseVariablesFunc = null)
    {
        if (self::$transformedObjects === null) {
            self::$transformedObjects = new WeakMap();
        }
        $this->transformUseVariablesFunc = $transformUseVariablesFunc;
        $this->resolveUseVariablesFunc = $resolveUseVariablesFunc;
    }

    public function transforms(mixed $value): bool
    {
        return $value instanceof Closure;
    }

    public function resolves(Stasis $value): bool
    {
        return $value->getClassName() === \Closure::class;
    }

    public function transform(mixed $value): mixed
    {
        if (!($value instanceof Closure)) {
            return false;
        }

        if (isset(self::$transformedObjects[$value])) {
            return self::$transformedObjects[$value];
        }

        $rf = new ReflectionFunction($value);
        $frozen = new Stasis(Closure::class);

        $closureThis = $rf->getClosureThis();
        $closureScopeClass = $rf->getClosureScopeClass();
        $closureCalledClass = $rf->getClosureCalledClass();

        $frozen->p['name'] = $rf->getName();
        $frozen->p['hash'] = Reflect::getHash($rf);
        if ($closureThis) {
            $frozen->p['callable'] = [&$closureThis, $rf->getName()];
        } elseif ($closureCalledClass) {
            $frozen->p['callable'] = [$closureCalledClass->getName(), $rf->getName()];
        } elseif (\function_exists($rf->getName())) {
            $frozen->p['callable'] = $rf->getName();
        } else {
            $frozen->p['callable'] = null;
        }
        $frozen->p['this'] = &$closureThis;
        $frozen->p['scope_class'] = $closureScopeClass?->getName();
        $frozen->p['called_class'] = $closureCalledClass?->getName();
        $frozen->p['namespace'] = $rf->getNamespaceName();

        /**
         * We can't serialize the code of native functions
         */
        if (!$rf->isUserDefined()) {
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
            if (self::resolveMethod($rc, $rf->getName())) {
                self::$transformedObjects[$value] = $frozen;
                self::$transformedObjects[$frozen] = $value;
                return $frozen;
            }
        }
        if ($closureCalledClass !== null) {
            $rm = self::resolveMethod($closureCalledClass, $rf->getName());
            if ($rm && $rm->isStatic()) {
                $frozen->p['callable'] = [$closureCalledClass->getName(), $rf->getName()];
                self::$transformedObjects[$value] = $frozen;
                self::$transformedObjects[$frozen] = $value;
                return $frozen;
            }
        }

        self::$transformedObjects[$value] = $frozen;
        self::$transformedObjects[$frozen] = $value;

        if ($this->transformUseVariablesFunc !== null) {
            $frozen->p['use'] = ($this->transformUseVariablesFunc)($rf->getClosureUsedVariables());
        } else {
            $frozen->p['use'] = $rf->getClosureUsedVariables();
        }
        $frozen->p['code'] = self::getCode($rf, $usedThis, $usedStatic, $isStaticFunction);
        if (!$usedThis) {
            $frozen->p['this'] = null;
        }
        $frozen->p['is_static_function'] = $isStaticFunction;
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


        /*
        $q = [ $value ];
        while (!empty($q)) {
            $i = array_shift($q);
            if ($i instanceof Stasis) {
                Debug::dump($i);
                throw new LogicException("Value " . $i->getClassName() . " has Stasis in a member");
            } elseif (\is_array($i)) {
                $q = [...$q, ...$i];
            } elseif (\is_object($i)) {
                $q = [...$q, ...\get_object_vars($i)];
            }
        }
        */

        if (\is_callable($value->p['callable'])) {
            $result = Closure::fromCallable($value->p['callable']);
            self::$transformedObjects[$value] = $result;
            self::$transformedObjects[$result] = $value;
            return $result;
        } elseif (\is_array($value->p['callable']) && \is_string($value->p['callable'][0]) && \class_exists($value->p['callable'][0])) {
            $callable = self::resolveCallable($value->p['callable']);
            if ($callable) {
                self::$transformedObjects[$value] = $callable;
                self::$transformedObjects[$callable] = $value;
                return $callable;
            }
        }

        $code = <<<PHP
            namespace {$value->p['namespace']} {
                return static function(array &\$useVars, ?object \$thisObject, ?string \$scopeClass): Closure {
                    extract(\$useVars, \EXTR_OVERWRITE | \EXTR_REFS);
                    return \Closure::bind({$value->p['code']}, \$thisObject, \$scopeClass);
                };
            }
            PHP;

        $hash = md5($code);
        if (!isset(self::$codeMakers[$hash])) {
            ClosureStream::register();
            self::$codeMakers[$hash] = require(ClosureStream::STREAM_PROTO . '://' . $code);
        }

        if ($this->resolveUseVariablesFunc !== null) {
            $use = ($this->resolveUseVariablesFunc)($value->p['use']);
        } else {
            $use = $value->p['use'];
        }

        $result = self::$codeMakers[$hash]($use, $value->p['this'], $value->p['scope_class']);

        self::$transformedObjects[$value] = $result;
        self::$transformedObjects[$result] = $value;

        return $result;
    }

    private static function resolveCallable(array|string $callable): ?Closure
    {
        if (is_callable($callable)) {
            return Closure::fromCallable($callable);
        }
        if (is_array($callable) && (\is_object($callable[0]) || class_exists($callable[0]))) {
            $rc = Reflect::getReflectionClass($callable[0]);
            $rm = self::resolveMethod($rc, $callable[1]);
            if ($rm) {
                if (!$rm->isStatic() && is_object($callable[0])) {
                    return $rm->getClosure($callable[0]);
                } else {
                    return $rm->getclosure();
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

    public static function getCode(ReflectionFunction $rf, bool &$usedThis = null, bool &$usedStatic = null, bool &$isStaticFunction = null): string
    {
        $hash = Reflect::getHash($rf);
        if (isset(self::$functionCache[$hash])) {
            $usedThis = self::$functionCache[$hash]['usedThis'];
            $usedStatic = self::$functionCache[$hash]['usedStatic'];
            return self::$functionCache[$hash]['code'];
        }
        $usedThis = null;
        $usedStatic = null;
        $isStaticFunction = false;
        $sourceFile = $rf->getFileName();
        if (\str_contains($sourceFile, 'eval()\'d')) {
            throw new RuntimeException("Can't serialize a closure that was generated with eval()");
        }
        if (isset(self::$tokenCache[$sourceFile])) {
            $tokens = self::$tokenCache[$sourceFile];
        } else {
            $tokens = self::$tokenCache[$sourceFile] = PhpToken::tokenize(file_get_contents($sourceFile));
        }

        $capture = false;
        $capturedTokens = [];
        $stackDepth = 0;
        $stack = [];
        foreach ($tokens as $idx => $token) {
            if (!$capture) {
                if ($token->line === $rf->getStartLine()) {
                    if ($token->id === \T_STATIC && $tokens[$idx+2]?->id === \T_FUNCTION) {
                        $capture = true;
                        $isStaticFunction = true;
                    } elseif ($token->id === T_FUNCTION || $token->id === \T_FN) {
                        $capture = true;
                    } else {
                        continue;
                    }
                } else {
                    continue;
                }
            }
            if (!$token->isIgnorable()) {
                if ($stackDepth === 0 && \str_contains(",)}];", $token->text)) {
                    break;
                }
                if (!$usedStatic && ($token->text === 'self' || $token->text === 'static' || $token->text === 'parent')) {
                    $usedStatic = true;
                }
                if (!$usedThis && $token->id === T_VARIABLE && ($token->text === '$this')) {
                    $usedThis = true;
                }
            }
            $capturedTokens[] = $token;
            if ($token->text === '{') {
                $stack[$stackDepth++] = '}';
            } elseif ($token->text === '(') {
                $stack[$stackDepth++] = ')';
            } elseif ($token->text === '[') {
                $stack[$stackDepth++] = ']';
            } elseif ($stackDepth > 0 && $stack[$stackDepth - 1] === $token->text) {
                --$stackDepth;
                if ($stackDepth === 0 && $token->text === '}') {
                    if ($token->line !== $rf->getEndLine() && $token->line === $rf->getStartLine()) {
                        $capture = false;
                        $capturedTokens = [];
                    } else {
                        break;
                    }
                }
            }
        }
        $codes = [];
        foreach ($capturedTokens as $token) {
            $codes[] = $token->text;
        }

        self::$functionCache[$hash] = [
            'code' => \implode('', $codes),
            'usedThis' => $usedThis,
            'usedStatic' => $usedStatic,
        ];

        return self::$functionCache[$hash]['code'];
    }

    /**
     * Find the first token on the specified line.
     *
     * @param PhpToken[] $tokens The array of PhpToken objects
     * @param int $line The line number to search for
     * @return int The offset in the tokens array where the line starts
     * @throws RuntimeException If the line number is not found in the tokens array
     */
    private static function findLineOffset(array &$tokens, int $line): int
    {
        $low = 0;
        $high = count($tokens) - 1;

        while ($low <= $high) {
            $mid = ($low + $high) >> 1;  // Equivalent to (int)(($low + $high) / 2) but faster
            $token = $tokens[$mid];

            if ($token->line > $line) {
                $high = $mid - 1;
            } elseif ($token->line < $line) {
                $low = $mid + 1;
            } else {
                // We've found a token on the desired line, now search backwards to find the first token on that line.
                while ($mid > 0 && $tokens[$mid - 1]->line === $line) {
                    $mid--;
                }
                return $mid;
            }
        }

        throw new RuntimeException("Token offset for line {$line} could not be found.");
    }
}
