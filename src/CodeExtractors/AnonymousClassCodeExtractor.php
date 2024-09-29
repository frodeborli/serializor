<?php

declare(strict_types=1);

namespace Serializor\CodeExtractors;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use ReflectionObject;
use RuntimeException;

use function in_array;

final class AnonymousClassCodeExtractor extends NodeVisitorAbstract implements CodeExtractor
{
    private ?ReflectionObject $reflection = null;

    /** @var array<string, string> $memberNamesToDiscard */
    private array $memberNamesToDiscard = [];

    private ?Class_ $anonymousClassNode = null;

    /** @param array<string, string> $memberNamesToDiscard */
    public function extract(
        ReflectionObject $reflection,
        array $memberNamesToDiscard,
        string $code,
    ): string {
        $this->reflection = $reflection;
        $this->memberNamesToDiscard = $memberNamesToDiscard;
        $this->anonymousClassNode = null;

        /** @var Stmt[] $ast */
        $ast = ((new ParserFactory())->createForNewestSupportedVersion())->parse($code);
        /** @var ?Class_ $this->anonymousClassNode */
        (new NodeTraverser(new NameResolver(), $this))->traverse($ast);

        $node = $this->anonymousClassNode
            ?? throw new RuntimeException('No class node was identified');

        return (new Standard())->prettyPrint([$node]);
    }

    /** @return ?Node[] */
    public function enterNode(Node $node): ?array
    {
        if (
            $node instanceof New_
            && $node->class instanceof Class_
            && $node->class->name === null
            && $node->getStartLine() === $this->reflection?->getStartLine()
            && $node->getEndLine() === $this->reflection?->getEndLine()
        ) {
            if ($this->anonymousClassNode !== null) {
                throw new RuntimeException('Class node was already identified');
            }

            $this->anonymousClassNode = $node->class;
        }

        if (
            $this->anonymousClassNode !== null
            && $node instanceof ClassMethod
            && in_array($node->name->name, $this->memberNamesToDiscard)
        ) {
            $properties = [];
            foreach ($node->params as $param) {
                if ($param->flags !== 0) {
                    /** @var string $param->var->name */
                    $properties[] = new Property(
                        flags: $param->flags,
                        props: [new PropertyItem($param->var->name)],
                        type: $param->type,
                    );
                }
            }

            return $properties;
        }

        return null;
    }
}
