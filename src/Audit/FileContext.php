<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

use Closure;
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

    /** @param Closure(string): void|null $onParse */
    public function __construct(
        public readonly string $path,
        public readonly string $contents,
        private readonly ?Closure $onParse = null,
        private readonly bool $newestSyntax = false,
    ) {}

    /** A declaration view reuses resolved nodes and original source locations.
     * @param  list<Node>  $nodes
     */
    public function withAst(array $nodes): self
    {
        $view = new self($this->path, $this->contents, $this->onParse, $this->newestSyntax);
        $view->ast = $nodes;
        $view->parsed = true;

        return $view;
    }

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
            if ($this->onParse !== null) {
                ($this->onParse)($this->path);
            }
            $factory = new ParserFactory;
            $parser = $this->newestSyntax ? $factory->createForNewestSupportedVersion() : $factory->createForHostVersion();
            $nodes = $parser->parse($this->contents);

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

    public function releaseAst(): void
    {
        $this->ast = null;
        $this->parsed = false;
        $this->parseError = null;
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
