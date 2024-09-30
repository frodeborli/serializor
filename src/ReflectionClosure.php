<?php

declare(strict_types=1);

namespace Serializor;

use Closure;
use ReflectionFunction;
use RuntimeException;
use Serializor\CodeExtractors\CodeExtractor;

use function file_get_contents;
use function preg_match;
use function str_contains;

final class ReflectionClosure extends ReflectionFunction
{
    /** @var array<string, FunctionDescription> $functionCache */
    private static array $functionCache = [];

    private FunctionDescription $functionDescription;

    /** @param callable-string|Closure $function */
    public function __construct(
        string|Closure $function,
        private CodeExtractor $codeExtractor,
    ) {
        parent::__construct($function);

        $this->functionDescription = static::$functionCache[Reflect::getHash($this)]
            ??= $this->generateFunctionDescription();
    }

    private function generateFunctionDescription(): FunctionDescription
    {
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

        $code = $this->codeExtractor->extract($this, [], file_get_contents($sourceFile));

        return new FunctionDescription(
            code: $code,
            usedThis: (bool) preg_match('/\$this(?:\W|$)/', $code),
            usedStatic: (bool) preg_match('/(?:\W|^)(?:self|static|parent)(?:\W|$)/', $code),
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
