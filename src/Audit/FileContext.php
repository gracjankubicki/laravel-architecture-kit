<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

final class FileContext
{
    /** @var array<int, Node>|null */
    private ?array $ast = null;

    private bool $parsed = false;

    private ?string $parseError = null;

    public function __construct(
        public readonly string $path,
        public readonly string $contents,
    ) {}

    /**
     * @return array<int, Node>|null
     */
    public function ast(): ?array
    {
        if ($this->parsed) {
            return $this->ast;
        }

        $this->parsed = true;

        try {
            $nodes = (new ParserFactory)->createForHostVersion()->parse($this->contents);

            if ($nodes !== null) {
                $traverser = new NodeTraverser;
                $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false]));
                $nodes = $traverser->traverse($nodes);
            }

            $this->ast = $nodes;
        } catch (Error $exception) {
            $this->parseError = $exception->getMessage();
            $this->ast = null;
        }

        return $this->ast;
    }

    public function parseError(): ?string
    {
        $this->ast();

        return $this->parseError;
    }

    public function resolvedName(Node\Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return $resolved instanceof Node\Name ? $resolved->toString() : $name->toString();
    }

    public function resolvedClassName(Node\Name|Node\Expr\ClassConstFetch|Node\Expr\StaticCall $node): ?string
    {
        $class = $node instanceof Node\Name ? $node : $node->class;

        return $class instanceof Node\Name ? $this->resolvedName($class) : null;
    }
}
