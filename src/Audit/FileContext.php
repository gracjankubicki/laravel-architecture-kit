<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit;

use Closure;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisDocument;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisSession;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node as SyntaxNode;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use RuntimeException;

final class FileContext
{
    /** @var array<string, Parser> Target grammar and legacy parser mode, never source tokens. */
    private static array $parsers = [];

    /** @var array<int, Node>|null */
    private ?array $ast = null;

    private bool $parsed = false;

    private ?string $parseError = null;

    private ?AnalysisDocument $document = null;

    private ?AnalysisSession $resolvedAnalysis = null;

    /** @param Closure(string): void|null $onParse */
    public function __construct(
        public readonly string $path,
        public readonly string $contents,
        private readonly ?Closure $onParse = null,
        private readonly bool $newestSyntax = false,
        private readonly ?AnalysisSession $analysis = null,
    ) {}

    /** A declaration view reuses resolved nodes and original source locations.
     * @param  list<Node>  $nodes
     */
    public function withAst(array $nodes): self
    {
        if ($this->analysisSession()->engine->engine !== 'php') {
            throw new RuntimeException('Legacy AST views require the PHP engine. Use withSyntax for portable rules.');
        }
        $view = new self($this->path, $this->contents, $this->onParse, false, $this->analysisSession());
        $view->ast = $nodes;
        $view->parsed = true;

        return $view;
    }

    /**
     * @return array<int, Node>|null
     */
    public function ast(): ?array
    {
        if ($this->analysisSession()->engine->engine !== 'php') {
            throw new RuntimeException('FileContext::ast requires the PHP engine. Migrate the rule to the shared syntax interface or select PHP explicitly.');
        }
        if ($this->parsed) {
            return $this->ast;
        }

        $this->parsed = true;

        try {
            if ($this->onParse !== null) {
                ($this->onParse)($this->path);
            }
            $version = $this->analysisSession()->phpVersion;
            $key = $version.':'.($this->newestSyntax ? 'legacy-newest' : 'target');
            $parser = self::$parsers[$key] ??= (new ParserFactory)->createForVersion(PhpVersion::fromString($version));
            try {
                $nodes = $parser->parse($this->contents);
            } finally {
                // The parser retains tokens and error stacks. Empty parsing clears
                // them before another file or audit can reuse the grammar.
                $parser->parse('');
            }

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
        if ($this->parsed) {
            return $this->parseError;
        }
        $this->syntax();

        return $this->document?->diagnostics[0]['message'] ?? null;
    }

    public function parseErrorLine(): int
    {
        $start = $this->analysisDocument()->diagnostics[0]['start'] ?? null;

        return is_int($start) ? substr_count(substr($this->contents, 0, $start), "\n") + 1 : 1;
    }

    public function releaseAst(): void
    {
        $this->ast = null;
        $this->parsed = false;
        $this->parseError = null;
        $this->document = null;
    }

    /** @return list<SyntaxNode>|null */
    public function syntax(): ?array
    {
        $document = $this->analysisDocument();
        foreach ($document->diagnostics as $diagnostic) {
            if ($diagnostic['category'] === 'unsupported_syntax') {
                throw new RuntimeException($this->path.': '.$diagnostic['message']);
            }
        }

        return $document->complete() ? $document->syntax : null;
    }

    /** @param list<SyntaxNode> $nodes */
    public function withSyntax(array $nodes): self
    {
        $document = $this->analysisDocument();
        $view = new self($this->path, $this->contents, $this->onParse, false, $this->analysisSession());
        $view->document = new AnalysisDocument($document->source, $document->engine, [], syntax: $nodes);

        return $view;
    }

    public function analysisSession(): AnalysisSession
    {
        return $this->resolvedAnalysis ??= $this->analysis ?? AnalysisRuntime::session();
    }

    public function withContents(string $contents): self
    {
        return new self($this->path, $contents, $this->onParse, false, $this->analysisSession());
    }

    public function analysisDocument(): AnalysisDocument
    {
        if ($this->document === null) {
            if ($this->onParse !== null) {
                ($this->onParse)($this->path);
            }
            $this->document = $this->analysisSession()->analyze($this->path, $this->contents);
        }

        return $this->document;
    }

    public function resolvedName(Node\Name|SyntaxNode $name): string
    {
        if ($name instanceof SyntaxNode) {
            if (! SyntaxNode::is($name, 'name')) {
                throw new RuntimeException('Name resolution requires a portable name node.');
            }
            $resolved = $name->getAttribute('resolvedName');

            return $resolved instanceof SyntaxNode ? $resolved->toString() : $name->toString();
        }
        $resolved = $name->getAttribute('resolvedName');

        return $resolved instanceof Node\Name ? $resolved->toString() : $name->toString();
    }

    public function resolvedClassName(Node\Name|Node\Expr\ClassConstFetch|Node\Expr\StaticCall|SyntaxNode $node): ?string
    {
        $class = $node instanceof Node\Name || SyntaxNode::is($node, 'name') ? $node : $node->class;

        return $class instanceof Node\Name || SyntaxNode::is($class, 'name') ? $this->resolvedName($class) : null;
    }
}
