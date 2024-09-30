<?php

declare(strict_types=1);

namespace Serializor\CodeExtractors;

use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use ReflectionFunction;
use Reflector;
use RuntimeException;

final class ClosureCodeExtractor extends NodeVisitorAbstract implements CodeExtractor
{
    private ?ReflectionFunction $reflection = null;

    private ?FunctionLike $functionLike = null;

    /** @param array<string, string> $memberNamesToDiscard */
    public function extract(
        Reflector $reflection,
        array $memberNamesToDiscard,
        string $code,
    ): string {
        $this->reflection = $reflection;
        $this->functionLike = null;

        /** @var Stmt[] $ast */
        $ast = ((new ParserFactory())->createForNewestSupportedVersion())->parse($code);
        /** @var ?FunctionLike $this->functionLike */
        (new NodeTraverser(new NameResolver(), $this))->traverse($ast);

        $node = $this->functionLike
            ?? throw new RuntimeException('No closure node was identified');

        return (new Standard())->prettyPrint([$node]);
    }

    public function enterNode(Node $node): void
    {
        if (
            $node instanceof FunctionLike
            && $node->getStartLine() === $this->reflection?->getStartLine()
            && $node->getEndLine() === $this->reflection?->getEndLine()
        ) {
            if ($this->functionLike !== null) {
                throw new RuntimeException('Closure node was already identified');
            }

            $this->functionLike = $node;
        }
    }
}
