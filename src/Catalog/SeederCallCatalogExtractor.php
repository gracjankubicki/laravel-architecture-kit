<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Seeder selectors only; parameter payloads never enter the cached catalog. */
final class SeederCallCatalogExtractor
{
    /** @var list<CatalogElement> */
    private array $elements = [];

    /** @var list<CatalogDiagnostic> */
    private array $diagnostics = [];

    /** @var array<int, CatalogElement> */
    private array $owners = [];

    private int $visited = 0;

    private bool $limited = false;

    public function extract(FileContext $file, CatalogFacts $php): CatalogFacts
    {
        $this->elements = $this->diagnostics = $this->owners = [];
        $this->visited = 0;
        $this->limited = false;
        foreach ($php->elements as $element) {
            if (in_array($element->kind, ['class', 'method', 'function', 'closure'], true)) {
                $this->owners[$element->offset] = $element;
            }
        }
        foreach ($file->syntax() ?? [] as $node) {
            $this->visit($file, $node, CatalogElement::identity($file->path, 'file', $file->path), '', false);
        }

        return new CatalogFacts($file->path, $this->elements, [], $this->diagnostics);
    }

    private function visit(FileContext $file, Node $node, string $owner, string $class, bool $bound): void
    {
        if ($this->limited) {
            return;
        }
        if (++$this->visited > 25000 || $this->visited % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
            $this->limited = true;
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Seeder calls reached their AST or memory budget.', max(1, $node->getStartLine()), $owner);

            return;
        }
        if (isset($this->owners[$node->getStartFilePos()]) && (Node::is($node, 'statement.class-like') || Node::is($node, 'statement.class-method') || Node::is($node, 'statement.function') || Node::is($node, 'expression.closure') || Node::is($node, 'expression.arrow-function'))) {
            $element = $this->owners[$node->getStartFilePos()];
            $owner = $element->id;
            if (Node::is($node, 'statement.class-like')) {
                $class = $element->name;
                $bound = false;
            } elseif (Node::is($node, 'statement.class-method')) {
                $bound = ! $node->isStatic();
            } elseif (Node::is($node, 'statement.function')) {
                $bound = false;
            } else {
                $bound = $bound && ! $node->static;
            }
        }
        if ($bound && $class !== '' && (Node::is($node, 'expression.method-call') || Node::is($node, 'expression.nullsafe-method-call'))
            && Node::is($node->var, 'expression.variable') && $node->var->name === 'this' && Node::is($node->name, 'identifier') && ! $node->isFirstClassCallable()
            && in_array(strtolower($node->name->toString()), ['call', 'callwith', 'callsilent', 'callonce'], true)) {
            $method = strtolower($node->name->toString());
            $valid = true;
            $selector = null;
            $seen = [];
            $names = in_array($method, ['call', 'callonce'], true) ? ['class', 'silent', 'parameters'] : ['class', 'parameters'];
            foreach ($node->getArgs() as $position => $argument) {
                $key = $argument->name?->toString() ?? ($names[$position] ?? 'unknown');
                $valid = $valid && ! $argument->unpack && in_array($key, $names, true) && ! isset($seen[$key]);
                $seen[$key] = true;
                if ($key === 'class') {
                    $selector = $argument->value;
                }
            }
            $targets = [];
            $items = Node::is($selector, 'expression.array') ? $selector->items : [$selector];
            if (count($items) > 128) {
                $valid = false;
                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Seeder target selectors reached their 128 item budget.', max(1, $node->getStartLine()), $owner);
            }
            foreach (array_slice($items, 0, 128) as $item) {
                if (Node::is($item, 'array-item')) {
                    if ($item->unpack || $item->key !== null) {
                        $valid = false;

                        continue;
                    }
                    $item = $item->value;
                }
                $type = null;
                if (Node::is($item, 'expression.class-const-fetch') && Node::is($item->class, 'name') && Node::is($item->name, 'identifier') && strtolower($item->name->toString()) === 'class'
                    && ! in_array(strtolower($item->class->toString()), ['self', 'static', 'parent'], true)) {
                    $type = $file->resolvedName($item->class);
                } elseif (Node::is($item, 'scalar.string')) {
                    $type = ltrim($item->value, '\\');
                }
                if (! is_string($type) || strlen($type) > 500 || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\z/D', $type) !== 1) {
                    $valid = false;

                    continue;
                }
                $targets[] = $type;
            }
            $id = CatalogElement::identity($file->path, 'seeder-operation', $method, $node->getStartFilePos());
            $this->elements[] = new CatalogElement($id, $method, 'seeder-operation', max(1, $node->getStartLine()), max(1, $node->getEndLine()), max(0, $node->getStartFilePos()), $owner,
                metadata: ['receiver' => $class, 'operation' => $method, 'targets' => array_values(array_unique($targets)), 'selector_resolved' => $valid && isset($seen['class']), 'execution_proven' => false]);
        }
        foreach ($node->getSubNodeNames() as $key) {
            $value = $node->$key;
            foreach (is_array($value) ? $value : [$value] as $child) {
                if (Node::is($child, 'node')) {
                    $this->visit($file, $child, $owner, $class, $bound);
                }
            }
        }
    }
}
