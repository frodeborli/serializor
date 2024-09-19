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
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use ReflectionObject;
use RuntimeException;

use function array_merge;

final class AnonymousClassCodeExtractor implements CodeExtractor
{
    /** @param array<string, string> $memberNamesToDiscard */
    public function extract(
        ReflectionObject $reflection,
        array $memberNamesToDiscard,
        string $code,
    ): string {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser = new NodeTraverser();
        $visitor = new AnonymousClassVisitor($reflection, $memberNamesToDiscard);
        /** @var Stmt[] $ast */
        $ast = $parser->parse($code);
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->getCode();
    }
}

/** @internal */
final class AnonymousClassVisitor extends NodeVisitorAbstract
{
    private ?Class_ $anonymousClassNode = null;

    /** @var Property[] $promotedProperties */
    private array $promotedProperties = [];

    /** @param string[] $memberNamesToDiscard */
    public function __construct(
        private ReflectionObject $reflection,
        private array $memberNamesToDiscard,
    ) {}

    public function enterNode(Node $node)
    {
        if (
            $node instanceof New_
            && $node->class instanceof Class_
            && $node->class->name === null
        ) {
            if (
                $node->getStartLine() === $this->reflection->getStartLine()
                && $node->getEndLine() === $this->reflection->getEndLine()
            ) {
                if ($this->anonymousClassNode !== null) {
                    throw new RuntimeException('Class node was already identified');
                }
                $this->anonymousClassNode = $node->class;
            }
        }
        if (
            $this->anonymousClassNode !== null
            && $node instanceof ClassMethod
            && in_array($node->name->name, $this->memberNamesToDiscard)
        ) {
            if ($node->name->name !== '__construct') {
                return NodeVisitor::REMOVE_NODE;
            }

            foreach ($node->params as $param) {
                if ($param->flags !== 0) {
                    $property = new Property(
                        flags: $param->flags,
                        props: [
                            new PropertyItem($param->var->name)
                        ],
                        type: $param->type,
                    );
                    $this->promotedProperties[] = $property;
                }
            }

            return NodeVisitor::REMOVE_NODE;
        }
    }

    public function leaveNode(Node $node)
    {
        if (
            $this->anonymousClassNode !== null
            && $node instanceof Class_
            && $this->promotedProperties !== []
        ) {
            /** @var list<Stmt> */
            $node->stmts = array_merge($this->promotedProperties, $node->stmts);
        }
    }

    public function getCode(): string
    {
        $node = $this->anonymousClassNode
            ?? throw new RuntimeException('no class node was identified');

        return (new Standard())->prettyPrint([$node]);
    }
}
