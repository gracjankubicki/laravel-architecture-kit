<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/** Source registration descriptors omit request values and never resolve the runtime container. */
final class FortifyCatalogExtractor
{
    /** @var list<CatalogElement> */
    private array $elements = [];

    /** @var list<CatalogDiagnostic> */
    private array $diagnostics = [];

    /** @var array<int, CatalogElement> */
    private array $owners = [];

    private int $visited = 0;

    public function extract(FileContext $file, CatalogFacts $php): CatalogFacts
    {
        $this->elements = $this->diagnostics = $this->owners = [];
        $this->visited = 0;
        foreach ($php->elements as $element) {
            $this->owners[$element->offset] = $element;
        }
        foreach ($file->ast() ?? [] as $node) {
            $this->visit($file, $node, CatalogElement::identity($file->path, 'file', $file->path));
        }

        return new CatalogFacts($file->path, $this->elements, [], $this->diagnostics);
    }

    private function visit(FileContext $file, Node $node, string $owner): void
    {
        if ($this->visited >= 25000) {
            return;
        }
        if (++$this->visited >= 25000 || $this->visited % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
            $this->visited = 25000;
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Fortify facts reached their AST or memory budget.', max(1, $node->getStartLine()), $owner);

            return;
        }
        if ($node instanceof Stmt\ClassLike || $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_ || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $owner = $this->owners[$node->getStartFilePos()]->id ?? $owner;
        }
        if ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier && ! $node->isFirstClassCallable()
            && $file->resolvedName($node->class) === 'Laravel\\Fortify\\Fortify' && CatalogFortifyRegistrations::supported($method = strtolower($node->name->toString()))) {
            $isView = isset(CatalogFortifyRegistrations::VIEWS[$method]);
            $arg = count($node->args) === 1 ? $node->getArgs()[0] : null;
            $value = $arg?->value;
            $valid = $arg !== null && ! $arg->unpack && ($arg->name === null || $arg->name->toString() === ($isView ? 'view' : 'callback'));
            $target = $value === null || $isView ? null : $this->type($file, $value);
            $callback = $value instanceof Expr\Closure || $value instanceof Expr\ArrowFunction ? ($this->owners[$value->getStartFilePos()]->id ?? null) : null;
            $view = $isView && $value instanceof Scalar\String_ && preg_match('/\A[a-zA-Z0-9_][a-zA-Z0-9_.\/:\-]{0,255}\z/D', $value->value) === 1 ? $value->value : null;
            $pipeline = [];
            $pipelineResolved = true;
            if (in_array($method, ['authenticatethrough', 'loginthrough'], true)) {
                $returned = $value instanceof Expr\ArrowFunction ? $value->expr
                    : ($value instanceof Expr\Closure && count($value->stmts) === 1 && $value->stmts[0] instanceof Stmt\Return_ ? $value->stmts[0]->expr : null);
                $pipelineResolved = $returned instanceof Expr\Array_ && count($returned->items) <= 128;
                foreach ($returned instanceof Expr\Array_ && count($returned->items) <= 128 ? $returned->items : [] as $item) {
                    $type = $item !== null && ! $item->unpack && ! $item->byRef ? $this->type($file, $item->value) : null;
                    $pipelineResolved = $pipelineResolved && $type !== null;
                    if ($type !== null) {
                        $pipeline[] = $type;
                    }
                }
                if ($returned instanceof Expr\Array_ && count($returned->items) > 128) {
                    $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Fortify pipeline exceeds its 128-step source budget.', max(1, $node->getStartLine()), $owner);
                }
            }
            $valid = $valid && ($isView ? $view !== null || $callback !== null : $target !== null || $callback !== null);
            $offset = max(0, $node->getStartFilePos());
            $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'fortify-operation', $method, $offset), 'Fortify '.$method, 'fortify-operation',
                max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner,
                metadata: ['package' => 'laravel/fortify', 'method' => $method, 'contract' => CatalogFortifyRegistrations::contract($method), 'target' => $target,
                    'callback' => $callback, 'view' => $view, 'pipeline' => $pipeline, 'pipeline_resolved' => $pipelineResolved, 'resolved' => $valid, 'execution_proven' => false]);
        }
        foreach ($node->getSubNodeNames() as $key) {
            foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) {
                if ($child instanceof Node) {
                    $this->visit($file, $child, $owner);
                }
            }
        }
    }

    private function type(FileContext $file, Expr $value): ?string
    {
        $name = $value instanceof Scalar\String_ ? ltrim($value->value, '\\')
            : ($value instanceof Expr\ClassConstFetch && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier && strtolower($value->name->toString()) === 'class'
                ? $file->resolvedName($value->class) : null);

        return $name !== null && strlen($name) <= 1000 && ! in_array(strtolower($name), ['self', 'static', 'parent'], true)
            && preg_match('/\A[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(?:\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*\z/D', $name) === 1 ? $name : null;
    }
}
