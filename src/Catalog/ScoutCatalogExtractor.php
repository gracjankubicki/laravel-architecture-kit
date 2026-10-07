<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/** Callback identity is taken from this exact chain, not a shared receiver type. */
final class ScoutCatalogExtractor
{
    public const PARAMETERS = [
        'search' => ['query', 'callback'], 'raw' => [], 'keys' => [], 'first' => [], 'get' => [], 'cursor' => [],
        'paginate' => ['perPage', 'pageName', 'page'], 'paginateraw' => ['perPage', 'pageName', 'page'],
        'simplepaginate' => ['perPage', 'pageName', 'page'], 'simplepaginateraw' => ['perPage', 'pageName', 'page'],
        'searchable' => [], 'unsearchable' => [], 'searchablesync' => [], 'unsearchablesync' => [], 'removeallfromsearch' => [],
    ];

    /** @var list<CatalogElement> */
    private array $elements = [];

    /** @var list<CatalogDiagnostic> */
    private array $diagnostics = [];

    /** @var array<int, string> */
    private array $owners = [];

    private int $visited = 0;

    public function extract(FileContext $file, CatalogFacts $php): CatalogFacts
    {
        $this->elements = $this->diagnostics = $this->owners = [];
        $this->visited = 0;
        foreach ($php->elements as $element) {
            $this->owners[$element->offset] = $element->id;
        }
        foreach ($file->ast() ?? [] as $node) {
            $this->visit($file, $node, CatalogElement::identity($file->path, 'file', $file->path));
        }
        $sites = [];
        foreach ($this->elements as $element) {
            if ($element->kind === 'scout-call-site') {
                $sites[$element->offset][$element->metadata['method']] = $element->metadata;
            }
        }
        $state = new CatalogScoutBuilderState($sites, $this->owners);
        $snapshots = $state->extract($file->ast() ?? []);
        foreach ($this->elements as $position => $element) {
            $snapshot = $snapshots[$element->offset][$element->metadata['method'] ?? ''] ?? null;
            if ($element->kind === 'scout-call-site' && $snapshot !== null) {
                $this->elements[$position] = new CatalogElement($element->id, $element->name, $element->kind, $element->line, $element->endLine, $element->offset, $element->parent, metadata: $snapshot);
            }
        }
        if ($state->limited) {
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Scout builder identity reached its AST, alias or memory budget.', 1);
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
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Scout facts reached their AST or memory budget.', max(1, $node->getStartLine()), $owner);

            return;
        }
        if ($node instanceof Stmt\ClassLike || $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_ || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $owner = $this->owners[$node->getStartFilePos()] ?? $owner;
        }
        if ($node instanceof Stmt\ClassMethod && in_array($hook = strtolower($node->name->toString()), ['searchableas', 'indexableas'], true)) {
            $value = count($node->stmts ?? []) === 1 && $node->stmts[0] instanceof Stmt\Return_ ? $node->stmts[0]->expr : null;
            $selector = $value instanceof Node\Scalar\String_ && preg_match('/\A[a-zA-Z0-9_][a-zA-Z0-9_.:\-]{0,255}\z/D', $value->value) === 1 ? $value->value : null;
            $offset = max(0, $node->getStartFilePos());
            $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'scout-index-selector', $hook, $offset), 'Scout '.$hook,
                'scout-index-selector', max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner,
                metadata: ['hook' => $hook, 'selector' => $selector, 'resolved' => $selector !== null]);
        }
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall) && $node->name instanceof Node\Identifier
            && isset(self::PARAMETERS[$method = strtolower($node->name->toString())]) && ! $node->isFirstClassCallable()) {
            $valid = count($node->args) <= count(self::PARAMETERS[$method]);
            $seen = [];
            foreach ($node->getArgs() as $position => $arg) {
                $name = $arg->name?->toString() ?? (self::PARAMETERS[$method][$position] ?? 'unknown');
                $valid = $valid && ! $arg->unpack && in_array($name, self::PARAMETERS[$method], true) && ! isset($seen[$name]);
                $seen[$name] = true;
            }
            $root = $node;
            $callbacks = $selected = [];
            $indexSupplied = false;
            $indexOverride = null;
            $complete = true;
            $depth = 0;
            while (($root instanceof Expr\MethodCall || $root instanceof Expr\StaticCall) && $depth++ < 16) {
                $valid = $valid && ! $root->isFirstClassCallable();
                $name = $root->name instanceof Node\Identifier ? strtolower($root->name->toString()) : '';
                if ($name === 'within' && ! $indexSupplied) {
                    $indexSupplied = true;
                    $arg = count($root->args) === 1 ? $root->getArgs()[0] : null;
                    $valid = $valid && $arg !== null && ! $arg->unpack && ($arg->name === null || $arg->name->toString() === 'index');
                    if ($arg?->value instanceof Node\Scalar\String_ && preg_match('/\A[a-zA-Z0-9_][a-zA-Z0-9_.:\-]{0,255}\z/D', $arg->value->value) === 1) {
                        $indexOverride = $arg->value->value;
                    }
                }
                if (in_array($name, ['search', 'query', 'withrawresults'], true) && ! isset($selected[$name])) {
                    $selected[$name] = true;
                    $position = $name === 'search' ? 1 : 0;
                    $value = null;
                    foreach ($root->getArgs() as $i => $arg) {
                        if ($arg->name?->toString() === 'callback' || $arg->name === null && $i === $position) {
                            $value = $arg->value;
                            $complete = $complete && ! $arg->unpack;
                        }
                    }
                    if ($value instanceof Expr\Closure || $value instanceof Expr\ArrowFunction) {
                        $target = $this->owners[$value->getStartFilePos()] ?? null;
                        if ($target !== null) {
                            $callbacks[] = ['target' => $target, 'hook' => $name];
                        } else {
                            $complete = false;
                        }
                    } elseif ($value !== null && ! ($value instanceof Expr\ConstFetch && strtolower($value->name->toString()) === 'null')) {
                        $complete = false;
                    }
                }
                if ($root instanceof Expr\StaticCall || $name === 'search') {
                    break;
                }
                $root = $root->var;
            }
            $rootOffset = ($root instanceof Expr\StaticCall || $root instanceof Expr\MethodCall) && $root->name instanceof Node\Identifier
                && strtolower($root->name->toString()) === 'search' ? max(0, $root->getStartFilePos()) : null;
            $offset = max(0, $node->getStartFilePos());
            $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'scout-call-site', $method, $offset), 'Scout candidate '.$method,
                'scout-call-site', max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner,
                metadata: ['method' => $method, 'valid' => $valid, 'root_offset' => $rootOffset, 'index_override_supplied' => $indexSupplied, 'index_override' => $indexOverride,
                    'callbacks' => $callbacks, 'callbacks_resolved' => $complete]);
        }
        foreach ($node->getSubNodeNames() as $key) {
            foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) {
                if ($child instanceof Node) {
                    $this->visit($file, $child, $owner);
                }
            }
        }
    }
}
