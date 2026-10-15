<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

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
        foreach ($file->syntax() ?? [] as $node) {
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
        if (Node::is($node, 'statement.class-like') || Node::is($node, 'statement.class-method') || Node::is($node, 'statement.function') || Node::is($node, 'expression.closure') || Node::is($node, 'expression.arrow-function')) {
            $owner = $this->owners[$node->getStartFilePos()]->id ?? $owner;
        }
        if (Node::is($node, 'expression.static-call') && Node::is($node->class, 'name') && Node::is($node->name, 'identifier') && ! $node->isFirstClassCallable()
            && $file->resolvedName($node->class) === 'Laravel\\Fortify\\Fortify' && CatalogFortifyRegistrations::supported($method = strtolower($node->name->toString()))) {
            $isView = isset(CatalogFortifyRegistrations::VIEWS[$method]);
            $arg = count($node->args) === 1 ? $node->getArgs()[0] : null;
            $value = $arg?->value;
            $valid = $arg !== null && ! $arg->unpack && ($arg->name === null || $arg->name->toString() === ($isView ? 'view' : 'callback'));
            $target = $value === null || $isView ? null : $this->type($file, $value);
            $callback = Node::is($value, 'expression.closure') || Node::is($value, 'expression.arrow-function') ? ($this->owners[$value->getStartFilePos()]->id ?? null) : null;
            $view = $isView && Node::is($value, 'scalar.string') && preg_match('/\A[a-zA-Z0-9_][a-zA-Z0-9_.\/:\-]{0,255}\z/D', $value->value) === 1 ? $value->value : null;
            $pipeline = [];
            $pipelineResolved = true;
            if (in_array($method, ['authenticatethrough', 'loginthrough'], true)) {
                $returned = Node::is($value, 'expression.arrow-function') ? $value->expr
                    : (Node::is($value, 'expression.closure') && count($value->stmts) === 1 && Node::is($value->stmts[0], 'statement.return') ? $value->stmts[0]->expr : null);
                $pipelineResolved = Node::is($returned, 'expression.array') && count($returned->items) <= 128;
                foreach (Node::is($returned, 'expression.array') && count($returned->items) <= 128 ? $returned->items : [] as $item) {
                    $type = $item !== null && ! $item->unpack && ! $item->byRef ? $this->type($file, $item->value) : null;
                    $pipelineResolved = $pipelineResolved && $type !== null;
                    if ($type !== null) {
                        $pipeline[] = $type;
                    }
                }
                if (Node::is($returned, 'expression.array') && count($returned->items) > 128) {
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
                if (Node::is($child, 'node')) {
                    $this->visit($file, $child, $owner);
                }
            }
        }
    }

    private function type(FileContext $file, Node $value): ?string
    {
        $name = Node::is($value, 'scalar.string') ? ltrim($value->value, '\\')
            : (Node::is($value, 'expression.class-const-fetch') && Node::is($value->class, 'name') && Node::is($value->name, 'identifier') && strtolower($value->name->toString()) === 'class'
                ? $file->resolvedName($value->class) : null);

        return $name !== null && strlen($name) <= 1000 && ! in_array(strtolower($name), ['self', 'static', 'parent'], true)
            && preg_match('/\A[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(?:\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*\z/D', $name) === 1 ? $name : null;
    }
}
