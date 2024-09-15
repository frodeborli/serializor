<?php

declare(strict_types=1);

namespace Serializor;

use Closure;
use PhpToken;
use ReflectionFunction;
use RuntimeException;

use function file_get_contents;
use function implode;
use function str_contains;

use const T_FN;
use const T_FUNCTION;
use const T_STATIC;

final class ReflectionClosure extends ReflectionFunction
{
    /** @var array<string, FunctionDescription> $functionCache */
    private static array $functionCache = [];

    /** @var array<string, array<int, PhpToken>> $tokenCache */
    private static array $tokenCache = [];

    private FunctionDescription $functionDescription;

    /** @param callable-string|Closure $function */
    public function __construct(string|Closure $function)
    {
        parent::__construct($function);

        $this->functionDescription = static::$functionCache[Reflect::getHash($this)]
            ??= $this->generateFunctionDescription();
    }

    private function generateFunctionDescription(): FunctionDescription
    {
        $usedThis = false;
        $usedStatic = false;
        $sourceFile = $this->getFileName();

        if ($sourceFile === false) {
            return new FunctionDescription(
                code: '/* native code */',
                usedThis: false,
                usedStatic: false,
            );
        }

        if (str_contains($sourceFile, 'eval()\'d')) {
            throw new RuntimeException("Can't serialize a closure that was generated with eval()");
        }
        $tokens = self::$tokenCache[$sourceFile]
            ??= PhpToken::tokenize(file_get_contents($sourceFile));

        $capture = false;
        $capturedTokens = [];
        $stackDepth = 0;
        $stack = [];

        foreach ($tokens as $idx => $token) {
            if (!$capture) {
                if ($token->line === $this->getStartLine()) {
                    if ($token->is(T_STATIC) && $tokens[$idx + 2]?->is([T_FUNCTION, T_FN])) {
                        $capture = true;
                    } elseif ($token->is([T_FUNCTION, T_FN])) {
                        $capture = true;
                    } else {
                        continue;
                    }
                } else {
                    continue;
                }
            }
            if (!$token->isIgnorable()) {
                if ($stackDepth === 0 && $token->is([',', ')', '}', ']', ';'])) {
                    break;
                }
                if (!$usedStatic && $token->is(['self', 'static', 'parent'])) {
                    $usedStatic = true;
                }
                if (!$usedThis && $token->is('$this')) {
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
                    if ($token->line !== $this->getEndLine() && $token->line === $this->getStartLine()) {
                        $capture = false;
                        $capturedTokens = [];
                    } else {
                        break;
                    }
                }
            }
        }

        return new FunctionDescription(
            code: implode($capturedTokens),
            usedThis: $usedThis,
            usedStatic: $usedStatic,
        );
    }

    public function getCode(): string
    {
        return $this->functionDescription->code;
    }

    public function usedThis(): bool
    {
        return $this->functionDescription->usedThis;
    }

    public function usedStatic(): bool
    {
        return $this->functionDescription->usedStatic;
    }
}

/** @internal */
final readonly class FunctionDescription
{
    public function __construct(
        public string $code,
        public bool $usedThis,
        public bool $usedStatic,
    ) {}
}
