<?php

declare(strict_types=1);

namespace Serializor;

use Closure;
use PhpToken;
use ReflectionFunction;
use RuntimeException;

/**
 * Stasis for anonymous closures with source code extraction.
 */
final class ClosureStasis extends Stasis
{
    private string $file;
    private int $line;
    private string $code;
    private string $namespace;
    private ?string $scope = null;
    private ?object $this = null;
    private array $use = [];
    private bool $isStatic = false;
    private array $useStatements = [];

    private static array $codeMakers = [];
    private static array $functionCache = [];
    /**
     * Pre-processed file info cache.
     * @var array<string, array{tokens: PhpToken[], namespaces: array}>
     */
    private static array $fileInfoCache = [];

    public function __construct() {}

    public function __serialize(): array
    {
        $data = [
            'f' => $this->file,
            'l' => $this->line,
            'c' => $this->code,
            'n' => $this->namespace,
        ];
        if ($this->scope !== null) {
            $data['s'] = $this->scope;
        }
        if ($this->this !== null) {
            $data['t'] = $this->this;
        }
        if (!empty($this->use)) {
            $data['u'] = $this->use;
        }
        if ($this->isStatic) {
            $data['is'] = true;
        }
        if (!empty($this->useStatements)) {
            $data['us'] = $this->useStatements;
        }
        return $data;
    }

    public function __unserialize(array $data): void
    {
        $this->file = $data['f'];
        $this->line = $data['l'];
        $this->code = $data['c'];
        $this->namespace = $data['n'];
        $this->scope = $data['s'] ?? null;
        $this->this = $data['t'] ?? null;
        $this->use = $data['u'] ?? [];
        $this->isStatic = $data['is'] ?? false;
        $this->useStatements = $data['us'] ?? [];
    }

    public function getClassName(): string
    {
        return Closure::class;
    }

    /**
     * Get the use variables for transformation.
     */
    public function &getUse(): array
    {
        return $this->use;
    }

    /**
     * Get the bound $this for transformation.
     */
    public function getThis(): ?object
    {
        return $this->this;
    }

    /**
     * Set the bound $this (after transformation).
     */
    public function setThis(mixed $this_): void
    {
        $this->this = $this_;
    }

    public static function fromClosure(Closure $value, ReflectionFunction $rf): ClosureStasis
    {
        $frozen = new ClosureStasis();

        $frozen->file = $rf->getFileName();
        $frozen->line = $rf->getStartLine();
        $frozen->namespace = $rf->getNamespaceName();
        $frozen->this = $rf->getClosureThis();
        $closureScopeClass = $rf->getClosureScopeClass();
        $frozen->scope = $closureScopeClass?->getName();

        $frozen->use = $rf->getClosureUsedVariables();
        $usedThis = false;
        $usedStatic = false;
        $isStaticFunction = false;
        $useStatements = [];
        $frozen->code = self::getCode($rf, $usedThis, $usedStatic, $isStaticFunction, $useStatements);

        if (!$usedThis) {
            $frozen->this = null;
        }
        if (!$usedStatic && !$usedThis) {
            $frozen->scope = null;
        }
        $frozen->isStatic = $isStaticFunction ?? false;
        $frozen->useStatements = $useStatements ?? [];

        return $frozen;
    }

    public function &getInstance(): mixed
    {
        if ($this->hasInstance()) {
            return $this->getCachedInstance();
        }

        $filteredUseStatements = [];
        foreach ($this->useStatements as $useStatement) {
            if (trim($this->namespace) === '' && !\str_contains($useStatement, '\\')) {
                continue;
            }
            $filteredUseStatements[] = $useStatement;
        }

        $useStatements = implode("\n", $filteredUseStatements);
        $isSimple = empty($this->use) && $this->this === null && $this->scope === null;

        if ($isSimple) {
            $code = "namespace {$this->namespace} { {$useStatements} return {$this->code}; }";
            $hash = \md5($code);
            if (!isset(self::$codeMakers[$hash])) {
                ClosureStream::register();
                self::$codeMakers[$hash] = require(ClosureStream::STREAM_PROTO . '://' . $code);
            }
            $result = self::$codeMakers[$hash];
        } else {
            $code = <<<PHP
                namespace {$this->namespace} {
                    {$useStatements}
                    return static function(array &\$useVars, ?object \$thisObject, ?string \$scopeClass): \Closure {
                        extract(\$useVars, \EXTR_OVERWRITE | \EXTR_REFS);
                        return \Closure::bind({$this->code}, \$thisObject, \$scopeClass);
                    };
                }
                PHP;

            $hash = \md5($code);
            if (!isset(self::$codeMakers[$hash])) {
                ClosureStream::register();
                self::$codeMakers[$hash] = require(ClosureStream::STREAM_PROTO . '://' . $code);
            }

            // Check if any dependency is still unresolved (circular reference case)
            $hasPending = false;
            foreach ($this->use as $v) {
                if ($v instanceof Stasis && !$v->hasInstance()) {
                    $hasPending = true;
                    break;
                }
            }
            if (!$hasPending && $this->this instanceof Stasis && !$this->this->hasInstance()) {
                $hasPending = true;
            }

            if ($hasPending) {
                // Create a lazy wrapper that resolves on first call
                $stasis = $this;
                $codeMaker = self::$codeMakers[$hash];
                $realClosure = null;
                $result = function (...$args) use ($stasis, $codeMaker, &$realClosure) {
                    if ($realClosure === null) {
                        $use = [];
                        foreach ($stasis->use as $k => $v) {
                            $use[$k] = ($v instanceof Stasis) ? $v->getInstance() : $v;
                        }
                        $thisObj = $stasis->this;
                        if ($thisObj instanceof Stasis) {
                            $thisObj = $thisObj->getInstance();
                        }
                        $scope = $thisObj !== null ? \get_class($thisObj) : $stasis->scope;
                        $realClosure = $codeMaker($use, $thisObj, $scope);
                    }
                    return $realClosure(...$args);
                };
            } else {
                // All dependencies resolved - build the closure directly
                $use = [];
                foreach ($this->use as $k => $v) {
                    $use[$k] = ($v instanceof Stasis) ? $v->getInstance() : $v;
                }
                $thisObject = $this->this;
                if ($thisObject instanceof Stasis) {
                    $thisObject = $thisObject->getInstance();
                }
                $scopeClass = $thisObject !== null ? \get_class($thisObject) : $this->scope;

                $result = self::$codeMakers[$hash]($use, $thisObject, $scopeClass);
            }
        }

        $this->setInstance($result);
        return $result;
    }

    // -------------------------------------------------------------------------
    // Source code extraction (moved from ClosureTransformer)
    // -------------------------------------------------------------------------

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

        $fileInfo = self::getFileInfo($sourceFile);
        $tokens = $fileInfo['tokens'];
        $tokenCount = count($tokens);
        $closureStartLine = $rf->getStartLine();

        $nsInfo = self::findNamespaceForLine($fileInfo['namespaces'], $closureStartLine);
        $namespace = $nsInfo['ns'] ?? '';
        $useStatements = $nsInfo['useStatements'] ?? [];

        $closureScopeClass = $rf->getClosureScopeClass();
        $magicClass = \var_export($closureScopeClass?->getName() ?? '', true);
        $magicFunction = \var_export($rf->getName(), true);
        $magicMethod = \var_export(
            ($closureScopeClass ? $closureScopeClass->getName() . '::' : '') . $rf->getName(),
            true
        );

        $startIdx = self::findLineOffset($tokens, $closureStartLine);
        if ($startIdx < 0) {
            throw new RuntimeException("Could not find closure start line {$closureStartLine} in {$sourceFile}");
        }

        $closuresOnLine = self::findClosuresOnLine($tokens, $closureStartLine, $startIdx);
        $targetClosureIdx = self::matchClosureBySignature($closuresOnLine, $rf);
        $targetStartIdx = $targetClosureIdx !== null ? $closuresOnLine[$targetClosureIdx]['startIdx'] : null;

        $capture = false;
        $capturedTokens = [];
        $stackDepth = 0;
        $stack = [];

        for ($idx = $startIdx; $idx < $tokenCount; $idx++) {
            $token = $tokens[$idx];

            if (!$capture && $token->line > $closureStartLine) {
                break;
            }

            if (!$capture) {
                if ($token->line !== $closureStartLine) {
                    continue;
                }
                if ($targetStartIdx !== null && $idx !== $targetStartIdx) {
                    continue;
                }
                if ($token->id === \T_STATIC) {
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

    private static function extractStatement(array &$tokens, int $startIndex): string
    {
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
     * @param PhpToken[] $tokens
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

            $isArrowFunc = false;
            $closureStartIdx = null;

            if ($token->id === \T_FN) {
                $isArrowFunc = true;
                $closureStartIdx = $i;
            } elseif ($token->id === \T_FUNCTION) {
                $nextNonWhitespace = $i + 1;
                while ($nextNonWhitespace < $tokenCount && $tokens[$nextNonWhitespace]->isIgnorable()) {
                    $nextNonWhitespace++;
                }
                if ($nextNonWhitespace < $tokenCount && $tokens[$nextNonWhitespace]->text === '(') {
                    $closureStartIdx = $i;
                }
            } elseif ($token->id === \T_STATIC) {
                $nextNonWhitespace = $i + 1;
                while ($nextNonWhitespace < $tokenCount && $tokens[$nextNonWhitespace]->isIgnorable()) {
                    $nextNonWhitespace++;
                }
                if ($nextNonWhitespace < $tokenCount) {
                    if ($tokens[$nextNonWhitespace]->id === \T_FN) {
                        $isArrowFunc = true;
                        $closureStartIdx = $nextNonWhitespace;
                        $i = $nextNonWhitespace;
                    } elseif ($tokens[$nextNonWhitespace]->id === \T_FUNCTION) {
                        $afterFunc = $nextNonWhitespace + 1;
                        while ($afterFunc < $tokenCount && $tokens[$afterFunc]->isIgnorable()) {
                            $afterFunc++;
                        }
                        if ($afterFunc < $tokenCount && $tokens[$afterFunc]->text === '(') {
                            $closureStartIdx = $i;
                            $i = $nextNonWhitespace;
                        }
                    }
                }
            }

            if ($closureStartIdx === null) {
                continue;
            }

            $params = [];
            $useVars = [];
            $parenDepth = 0;
            $state = 'searching';

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
                        $params[] = substr($t->text, 1);
                    }
                } elseif ($state === 'after_params') {
                    if ($t->id === \T_USE) {
                        $state = 'before_use_vars';
                    } elseif ($t->text === '{' || $t->text === '=>' || $t->text === ':') {
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
                        $useVars[] = substr($t->text, 1);
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

    private static function matchClosureBySignature(array $closuresOnLine, ReflectionFunction $rf): ?int
    {
        if (count($closuresOnLine) <= 1) {
            return count($closuresOnLine) === 1 ? 0 : null;
        }

        $expectedParams = [];
        foreach ($rf->getParameters() as $param) {
            $expectedParams[] = $param->getName();
        }

        $expectedUseVars = array_keys($rf->getStaticVariables());

        $matches = [];
        foreach ($closuresOnLine as $idx => $closureInfo) {
            $paramsMatch = $closureInfo['params'] === $expectedParams;

            $useVarsMatch = true;
            if (!empty($closureInfo['useVars']) || !empty($expectedUseVars)) {
                $foundUseVars = $closureInfo['useVars'];
                sort($foundUseVars);
                $expectedSorted = $expectedUseVars;
                sort($expectedSorted);

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
            foreach ($closuresOnLine as $idx => $closureInfo) {
                if ($closureInfo['params'] === $expectedParams) {
                    $matches[] = $idx;
                }
            }
            if (count($matches) === 1) {
                return $matches[0];
            }
        }

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
     * @return array{tokens: PhpToken[], namespaces: array}
     */
    private static function getFileInfo(string $sourceFile): array
    {
        if (isset(self::$fileInfoCache[$sourceFile])) {
            return self::$fileInfoCache[$sourceFile];
        }

        $tokens = PhpToken::tokenize(file_get_contents($sourceFile));
        $count = count($tokens);

        $magicDir = \var_export(\dirname($sourceFile), true);
        $magicFile = \var_export($sourceFile, true);

        $namespaces = [];
        $currentNs = '';
        $currentNsStart = 1;
        $currentUseStatements = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->id === \T_DIR) {
                $tokens[$i] = new PhpToken(\T_CONSTANT_ENCAPSED_STRING, $magicDir, $token->line, $token->pos);
            } elseif ($token->id === \T_FILE) {
                $tokens[$i] = new PhpToken(\T_CONSTANT_ENCAPSED_STRING, $magicFile, $token->line, $token->pos);
            } elseif ($token->id === \T_LINE) {
                $tokens[$i] = new PhpToken(\T_LNUMBER, (string) $token->line, $token->line, $token->pos);
            }

            if ($token->id === \T_NAMESPACE) {
                if ($currentNs !== '' || !empty($currentUseStatements)) {
                    $namespaces[] = [
                        'ns' => $currentNs,
                        'useStatements' => $currentUseStatements,
                        'startLine' => $currentNsStart,
                        'endLine' => $token->line - 1,
                    ];
                }
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
     * @param PhpToken[] $tokens
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
                while ($mid > 0 && $tokens[$mid - 1]->line === $line) {
                    $mid--;
                }
                return $mid;
            }
        }

        return -1;
    }
}
