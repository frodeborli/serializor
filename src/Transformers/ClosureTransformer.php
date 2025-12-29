<?php

declare(strict_types=1);

namespace Serializor\Transformers;

use Closure;
use PhpToken;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use RuntimeException;
use Serializor\ClosureStream;
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
    private static array $functionCache = [];
    /**
     * Pre-processed file info cache: tokens with magic constants replaced,
     * and namespace/use statement ranges pre-computed.
     *
     * @var array<string, array{tokens: PhpToken[], namespaces: array}>
     */
    private static array $fileInfoCache = [];
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
        $name = $rf->getName();
        $closureThis = $rf->getClosureThis();
        $closureCalledClass = $rf->getClosureCalledClass();

        // Fast path: named functions and methods that exist in the codebase
        // Only store the callable reference - no source extraction needed
        if ($closureThis !== null) {
            $rc = Reflect::getReflectionClass($closureThis);
            if (self::resolveMethod($rc, $name)) {
                $frozen = new Stasis(Closure::class);
                $frozen->p['callable'] = [$closureThis, $name];
                self::$transformedObjects[$value] = $frozen;
                self::$transformedObjects[$frozen] = $value;
                return $frozen;
            }
        } elseif ($closureCalledClass !== null) {
            if (self::resolveMethod($closureCalledClass, $name)) {
                $frozen = new Stasis(Closure::class);
                $frozen->p['callable'] = [$closureCalledClass->getName(), $name];
                self::$transformedObjects[$value] = $frozen;
                self::$transformedObjects[$frozen] = $value;
                return $frozen;
            }
        } elseif (\function_exists($name)) {
            $frozen = new Stasis(Closure::class);
            $frozen->p['callable'] = $name;
            self::$transformedObjects[$value] = $frozen;
            self::$transformedObjects[$frozen] = $value;
            return $frozen;
        } elseif (!$rf->isUserDefined()) {
            // Native function without a name we can call - store null callable
            $frozen = new Stasis(Closure::class);
            $frozen->p['callable'] = null;
            self::$transformedObjects[$value] = $frozen;
            self::$transformedObjects[$frozen] = $value;
            return $frozen;
        }

        // Full path: anonymous closures need source extraction
        $frozen = new Stasis(Closure::class);
        $closureScopeClass = $rf->getClosureScopeClass();

        $frozen->p['name'] = $name;
        $frozen->p['hash'] = Reflect::getHash($rf);
        $frozen->p['callable'] = null;
        $frozen->p['this'] = $closureThis;
        $frozen->p['scope_class'] = $closureScopeClass?->getName();
        $frozen->p['called_class'] = $closureCalledClass?->getName();
        $frozen->p['namespace'] = $rf->getNamespaceName();

        self::$transformedObjects[$value] = $frozen;
        self::$transformedObjects[$frozen] = $value;

        if ($this->transformUseVariablesFunc !== null) {
            $frozen->p['use'] = ($this->transformUseVariablesFunc)($rf->getClosureUsedVariables());
        } else {
            $frozen->p['use'] = $rf->getClosureUsedVariables();
        }
        $frozen->p['code'] = self::getCode($rf, $usedThis, $usedStatic, $isStaticFunction, $useStatements);
        if (!$usedThis) {
            $frozen->p['this'] = null;
        }
        if (!$usedStatic && !$usedThis) {
            $frozen->p['scope_class'] = null;
        }
        $frozen->p['is_static_function'] = $isStaticFunction;
        $frozen->p['use_statements'] = $useStatements;
        return $frozen;
    }

    public function resolve(mixed $value): mixed
    {
        \assert($value instanceof Stasis && $value->getClassName() === Closure::class, "Can't resolve " . get_debug_type($value));

        if (isset(self::$transformedObjects[$value])) {
            return self::$transformedObjects[$value];
        }

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

        $filteredUseStatements = [];
        foreach ($value->p['use_statements'] ?? [] as $useStatement) {
            if (trim($value->p['namespace']) == '' && !str_contains($useStatement, '\\')) {
                continue;
            }
            $filteredUseStatements[] = $useStatement;
        }

        $useStatements = implode("\n", $filteredUseStatements);
        $isSimple = empty($value->p['use']) && $value->p['this'] === null && $value->p['scope_class'] === null;

        if ($isSimple) {
            // Fast path: no use vars, no $this, no scope - just return the closure directly
            $code = "namespace {$value->p['namespace']} { {$useStatements} return {$value->p['code']}; }";
            $hash = \md5($code);
            if (!isset(self::$codeMakers[$hash])) {
                ClosureStream::register();
                self::$codeMakers[$hash] = require(ClosureStream::STREAM_PROTO . '://' . $code);
            }
            $result = self::$codeMakers[$hash];
        } else {
            $code = <<<PHP
                namespace {$value->p['namespace']} {
                    {$useStatements}
                    return static function(array &\$useVars, ?object \$thisObject, ?string \$scopeClass): \Closure {
                        extract(\$useVars, \EXTR_OVERWRITE | \EXTR_REFS);
                        return \Closure::bind({$value->p['code']}, \$thisObject, \$scopeClass);
                    };
                }
                PHP;

            $hash = \md5($code);
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
        }

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

    public static function getCode(ReflectionFunction $rf, bool &$usedThis = null, bool &$usedStatic = null, bool &$isStaticFunction = null, array &$useStatements = null): string
    {
        $hash = Reflect::getHash($rf);
        if (isset(self::$functionCache[$hash])) {
            $usedThis = self::$functionCache[$hash]['usedThis'];
            $usedStatic = self::$functionCache[$hash]['usedStatic'];
            $useStatements = self::$functionCache[$hash]['useStatements'];
            return self::$functionCache[$hash]['code'];
        }
        $usedThis = null;
        $usedStatic = null;
        $isStaticFunction = false;
        $sourceFile = $rf->getFileName();
        if (\str_contains($sourceFile, 'eval()\'d')) {
            throw new RuntimeException("Can't serialize a closure that was generated with eval()");
        }

        // Get preprocessed file info (cached per file)
        $fileInfo = self::getFileInfo($sourceFile);
        $tokens = $fileInfo['tokens'];
        $tokenCount = count($tokens);
        $closureStartLine = $rf->getStartLine();

        // Get namespace and use statements from preprocessed data
        $nsInfo = self::findNamespaceForLine($fileInfo['namespaces'], $closureStartLine);
        $namespace = $nsInfo['ns'] ?? '';
        $useStatements = $nsInfo['useStatements'] ?? [];

        // Prepare closure-specific magic constants
        $closureScopeClass = $rf->getClosureScopeClass();
        $magicClass = \var_export($closureScopeClass?->getName() ?? '', true);
        $magicFunction = \var_export($rf->getName(), true);
        $magicMethod = \var_export(
            ($closureScopeClass ? $closureScopeClass->getName() . '::' : '') . $rf->getName(),
            true
        );

        // Use binary search to find the starting position
        $startIdx = self::findLineOffset($tokens, $closureStartLine);
        if ($startIdx < 0) {
            throw new RuntimeException("Could not find closure start line {$closureStartLine} in {$sourceFile}");
        }

        // Check for multiple closures on the same line and disambiguate or throw
        $closuresOnLine = self::findClosuresOnLine($tokens, $closureStartLine, $startIdx);
        $targetClosureIdx = self::matchClosureBySignature($closuresOnLine, $rf);
        $targetStartIdx = $targetClosureIdx !== null ? $closuresOnLine[$targetClosureIdx]['startIdx'] : null;

        // Capture closure tokens starting from binary search position
        $capture = false;
        $capturedTokens = [];
        $stackDepth = 0;
        $stack = [];

        for ($idx = $startIdx; $idx < $tokenCount; $idx++) {
            $token = $tokens[$idx];

            // Stop if we've passed the closure's start line without capturing
            if (!$capture && $token->line > $closureStartLine) {
                break;
            }

            if (!$capture) {
                if ($token->line !== $closureStartLine) {
                    continue;
                }
                // If we have a specific target index from disambiguation, only start at that index
                if ($targetStartIdx !== null && $idx !== $targetStartIdx) {
                    continue;
                }
                if ($token->id === \T_STATIC) {
                    // Check for static function/fn - skip ignorable tokens to find the next keyword
                    $nextNonIgnorable = $idx + 1;
                    while ($nextNonIgnorable < $tokenCount && $tokens[$nextNonIgnorable]->isIgnorable()) {
                        $nextNonIgnorable++;
                    }
                    if ($nextNonIgnorable < $tokenCount && $tokens[$nextNonIgnorable]->id === \T_FUNCTION) {
                        $capture = true;
                        $isStaticFunction = true;
                    } elseif ($nextNonIgnorable < $tokenCount && $tokens[$nextNonIgnorable]->id === \T_FN) {
                        $capture = true;
                        $isStaticFunction = true;
                    } else {
                        continue;
                    }
                } elseif ($token->id === T_FUNCTION || $token->id === \T_FN) {
                    $capture = true;
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
            // Replace closure-specific magic constants with their actual values
            // Note: T_DIR, T_FILE, T_LINE are already replaced in getFileInfo()
            $text = match ($token->id) {
                \T_CLASS_C => $magicClass,
                \T_FUNC_C => $magicFunction,
                \T_METHOD_C => $magicMethod,
                \T_NS_C => \var_export($namespace, true),
                default => $token->text,
            };
            $codes[] = $text;
        }

        self::$functionCache[$hash] = [
            'code' => \implode('', $codes),
            'usedThis' => $usedThis,
            'usedStatic' => $usedStatic,
            'useStatements' => $useStatements,
        ];

        return self::$functionCache[$hash]['code'];
    }

    private static function extractStatement(array &$tokens, int $startIndex): string {
        $captured = [];
        for (; $startIndex < count($tokens); $startIndex++) {
            if ($tokens[$startIndex]->isIgnorable()) {
                $captured[] = ' ';
            } else {
                $captured[] = $tokens[$startIndex]->text;
            }
            if ($tokens[$startIndex]->text === ";") {
                break;
            }
        }
        return implode("", $captured);
    }

    /**
     * Find all closures on a given line and extract their signatures.
     *
     * @param PhpToken[] $tokens
     * @param int $line
     * @return array Array of ['startIdx' => int, 'params' => string[], 'useVars' => string[]]
     */
    private static function findClosuresOnLine(array &$tokens, int $line, int $startIdx = 0): array
    {
        $closures = [];
        $tokenCount = count($tokens);

        for ($i = $startIdx; $i < $tokenCount; $i++) {
            $token = $tokens[$i];
            if ($token->line !== $line) {
                if ($token->line > $line) {
                    break;
                }
                continue;
            }

            // Check for closure start: function or fn keyword
            $isArrowFunc = false;
            $closureStartIdx = null;

            if ($token->id === \T_FN) {
                $isArrowFunc = true;
                $closureStartIdx = $i;
            } elseif ($token->id === \T_FUNCTION) {
                // Make sure this is a closure (anonymous function), not a named function
                // Look for ( after function keyword
                $nextNonWhitespace = $i + 1;
                while ($nextNonWhitespace < $tokenCount && $tokens[$nextNonWhitespace]->isIgnorable()) {
                    $nextNonWhitespace++;
                }
                if ($nextNonWhitespace < $tokenCount && $tokens[$nextNonWhitespace]->text === '(') {
                    $closureStartIdx = $i;
                }
            } elseif ($token->id === \T_STATIC) {
                // Check for static function or static fn
                $nextNonWhitespace = $i + 1;
                while ($nextNonWhitespace < $tokenCount && $tokens[$nextNonWhitespace]->isIgnorable()) {
                    $nextNonWhitespace++;
                }
                if ($nextNonWhitespace < $tokenCount) {
                    if ($tokens[$nextNonWhitespace]->id === \T_FN) {
                        // static fn - capture starts at T_FN (the original code captures at fn, not static)
                        $isArrowFunc = true;
                        $closureStartIdx = $nextNonWhitespace;
                        $i = $nextNonWhitespace; // Skip to fn
                    } elseif ($tokens[$nextNonWhitespace]->id === \T_FUNCTION) {
                        // static function - check it's a closure, capture starts at T_STATIC
                        $afterFunc = $nextNonWhitespace + 1;
                        while ($afterFunc < $tokenCount && $tokens[$afterFunc]->isIgnorable()) {
                            $afterFunc++;
                        }
                        if ($afterFunc < $tokenCount && $tokens[$afterFunc]->text === '(') {
                            $closureStartIdx = $i; // Keep at T_STATIC for static function
                            $i = $nextNonWhitespace;
                        }
                    }
                }
            }

            if ($closureStartIdx === null) {
                continue;
            }

            // Extract parameter names and use variables
            $params = [];
            $useVars = [];
            $parenDepth = 0;
            $state = 'searching'; // searching, in_params, after_params, in_use

            for ($j = $i + 1; $j < $tokenCount; $j++) {
                $t = $tokens[$j];

                if ($t->isIgnorable()) {
                    continue;
                }

                if ($state === 'searching') {
                    if ($t->text === '(') {
                        $state = 'in_params';
                        $parenDepth = 1;
                    }
                } elseif ($state === 'in_params') {
                    if ($t->text === '(') {
                        $parenDepth++;
                    } elseif ($t->text === ')') {
                        $parenDepth--;
                        if ($parenDepth === 0) {
                            $state = 'after_params';
                        }
                    } elseif ($t->id === \T_VARIABLE) {
                        $params[] = substr($t->text, 1); // Remove $
                    }
                } elseif ($state === 'after_params') {
                    if ($t->id === \T_USE) {
                        $state = 'before_use_vars';
                    } elseif ($t->text === '{' || $t->text === '=>' || $t->text === ':') {
                        // Closure body or return type, we're done
                        break;
                    }
                } elseif ($state === 'before_use_vars') {
                    if ($t->text === '(') {
                        $state = 'in_use';
                        $parenDepth = 1;
                    }
                } elseif ($state === 'in_use') {
                    if ($t->text === '(') {
                        $parenDepth++;
                    } elseif ($t->text === ')') {
                        $parenDepth--;
                        if ($parenDepth === 0) {
                            break;
                        }
                    } elseif ($t->id === \T_VARIABLE) {
                        $useVars[] = substr($t->text, 1); // Remove $
                    }
                }
            }

            $closures[] = [
                'startIdx' => $closureStartIdx,
                'params' => $params,
                'useVars' => $useVars,
            ];
        }

        return $closures;
    }

    /**
     * Match a closure's reflection info against found closures on the line.
     *
     * @param array $closuresOnLine From findClosuresOnLine()
     * @param ReflectionFunction $rf
     * @return int|null Index in closuresOnLine array, or null if no unique match
     * @throws SerializerError If multiple closures match (ambiguous)
     */
    private static function matchClosureBySignature(array $closuresOnLine, ReflectionFunction $rf): ?int
    {
        if (count($closuresOnLine) <= 1) {
            return count($closuresOnLine) === 1 ? 0 : null;
        }

        // Get expected parameters
        $expectedParams = [];
        foreach ($rf->getParameters() as $param) {
            $expectedParams[] = $param->getName();
        }

        // Get expected use variables (for arrow functions, these are in getStaticVariables)
        $expectedUseVars = array_keys($rf->getStaticVariables());

        $matches = [];
        foreach ($closuresOnLine as $idx => $closureInfo) {
            $paramsMatch = $closureInfo['params'] === $expectedParams;

            // For use vars, we need to check if they match
            // Arrow functions capture implicitly, traditional closures use explicit use()
            $useVarsMatch = true;
            if (!empty($closureInfo['useVars']) || !empty($expectedUseVars)) {
                // Sort both arrays for comparison
                $foundUseVars = $closureInfo['useVars'];
                sort($foundUseVars);
                $expectedSorted = $expectedUseVars;
                sort($expectedSorted);

                // For traditional closures, use vars should match exactly
                if (!empty($closureInfo['useVars'])) {
                    $useVarsMatch = $foundUseVars === $expectedSorted;
                }
            }

            if ($paramsMatch && $useVarsMatch) {
                $matches[] = $idx;
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        if (count($matches) === 0) {
            // Try matching by params only (arrow functions don't have explicit use)
            foreach ($closuresOnLine as $idx => $closureInfo) {
                if ($closureInfo['params'] === $expectedParams) {
                    $matches[] = $idx;
                }
            }
            if (count($matches) === 1) {
                return $matches[0];
            }
        }

        // Build error message with details about what was found
        $details = [];
        foreach ($closuresOnLine as $idx => $info) {
            $paramStr = empty($info['params']) ? '()' : '($' . implode(', $', $info['params']) . ')';
            $useStr = empty($info['useVars']) ? '' : ' use ($' . implode(', $', $info['useVars']) . ')';
            $details[] = "  #{$idx}: {$paramStr}{$useStr}";
        }
        $expectedParamStr = empty($expectedParams) ? '()' : '($' . implode(', $', $expectedParams) . ')';
        $expectedUseStr = empty($expectedUseVars) ? '' : ' [captures: $' . implode(', $', $expectedUseVars) . ']';

        throw new SerializerError(
            "Cannot serialize closure: multiple closures found on the same line and cannot be uniquely distinguished.\n" .
            "Target closure signature: {$expectedParamStr}{$expectedUseStr}\n" .
            "Found closures:\n" . implode("\n", $details) . "\n" .
            "Tip: Place each closure on its own line, or use distinct parameter names."
        );
    }

    /**
     * Preprocess a file: tokenize, replace magic constants, and extract namespace/use info.
     * Results are cached per file.
     *
     * @return array{tokens: PhpToken[], namespaces: array}
     */
    private static function getFileInfo(string $sourceFile): array
    {
        if (isset(self::$fileInfoCache[$sourceFile])) {
            return self::$fileInfoCache[$sourceFile];
        }

        $tokens = PhpToken::tokenize(file_get_contents($sourceFile));
        $count = count($tokens);

        // Pre-compute magic constant values
        $magicDir = \var_export(\dirname($sourceFile), true);
        $magicFile = \var_export($sourceFile, true);

        // Extract namespace ranges and use statements, and replace magic constants
        $namespaces = [];
        $currentNs = '';
        $currentNsStart = 1;
        $currentUseStatements = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // Replace file-level magic constants inline
            if ($token->id === \T_DIR) {
                $tokens[$i] = new PhpToken(\T_CONSTANT_ENCAPSED_STRING, $magicDir, $token->line, $token->pos);
            } elseif ($token->id === \T_FILE) {
                $tokens[$i] = new PhpToken(\T_CONSTANT_ENCAPSED_STRING, $magicFile, $token->line, $token->pos);
            } elseif ($token->id === \T_LINE) {
                $tokens[$i] = new PhpToken(\T_LNUMBER, (string) $token->line, $token->line, $token->pos);
            }

            if ($token->id === \T_NAMESPACE) {
                // Save previous namespace range
                if ($currentNs !== '' || !empty($currentUseStatements)) {
                    $namespaces[] = [
                        'ns' => $currentNs,
                        'useStatements' => $currentUseStatements,
                        'startLine' => $currentNsStart,
                        'endLine' => $token->line - 1,
                    ];
                }
                // Extract new namespace name
                $currentNs = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    $t = $tokens[$j];
                    if ($t->id === \T_NAME_QUALIFIED || $t->id === \T_STRING) {
                        $currentNs = $t->text;
                        break;
                    }
                    if ($t->text === ';' || $t->text === '{') {
                        break;
                    }
                }
                $currentNsStart = $token->line;
                $currentUseStatements = [];
            } elseif ($token->id === \T_USE && $i >= 2) {
                // Check if this is a use statement (not closure use)
                $isClosureUse = false;
                for ($j = $i + 1; $j < $count; $j++) {
                    if (!$tokens[$j]->isIgnorable()) {
                        if ($tokens[$j]->text === '(') {
                            $isClosureUse = true;
                        }
                        break;
                    }
                }
                if (!$isClosureUse) {
                    $currentUseStatements[] = self::extractStatement($tokens, $i);
                }
            }
        }

        // Save final namespace range
        $lastLine = $tokens[$count - 1]->line ?? PHP_INT_MAX;
        $namespaces[] = [
            'ns' => $currentNs,
            'useStatements' => $currentUseStatements,
            'startLine' => $currentNsStart,
            'endLine' => $lastLine,
        ];

        self::$fileInfoCache[$sourceFile] = ['tokens' => $tokens, 'namespaces' => $namespaces];
        return self::$fileInfoCache[$sourceFile];
    }

    /**
     * Find the namespace info for a given line number.
     */
    private static function findNamespaceForLine(array $namespaces, int $line): array
    {
        foreach ($namespaces as $ns) {
            if ($line >= $ns['startLine'] && $line <= $ns['endLine']) {
                return $ns;
            }
        }
        return ['ns' => '', 'useStatements' => []];
    }

    /**
     * Find the first token on the specified line using binary search.
     *
     * @param PhpToken[] $tokens
     * @return int The offset where the line starts, or -1 if not found
     */
    private static function findLineOffset(array $tokens, int $line): int
    {
        $low = 0;
        $high = count($tokens) - 1;

        while ($low <= $high) {
            $mid = ($low + $high) >> 1;
            $tokenLine = $tokens[$mid]->line;

            if ($tokenLine > $line) {
                $high = $mid - 1;
            } elseif ($tokenLine < $line) {
                $low = $mid + 1;
            } else {
                // Found a token on the line, walk backward to find first token on this line
                while ($mid > 0 && $tokens[$mid - 1]->line === $line) {
                    $mid--;
                }
                return $mid;
            }
        }

        return -1;
    }
}
