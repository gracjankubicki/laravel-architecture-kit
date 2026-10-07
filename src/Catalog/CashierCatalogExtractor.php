<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

final class CashierCatalogExtractor
{
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

        return new CatalogFacts($file->path, $this->elements, [], $this->diagnostics);
    }

    private function visit(FileContext $file, Node $node, string $owner): void
    {
        if ($this->visited >= 25000) {
            return;
        }
        if (++$this->visited >= 25000 || $this->visited % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
            $this->visited = 25000;
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Cashier facts reached their AST or memory budget.', max(1, $node->getStartLine()), $owner);

            return;
        }
        if ($node instanceof Stmt\ClassLike || $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_ || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $owner = $this->owners[$node->getStartFilePos()] ?? $owner;
        }
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall) && $node->name instanceof Node\Identifier && ! $node->isFirstClassCallable()) {
            $method = strtolower($node->name->toString());
            if (isset(CatalogCashierOperations::MODEL[$method]) || isset(CatalogCashierOperations::BUILDER[$method]) || isset(CatalogCashierOperations::SUBSCRIPTION[$method])) {
                $valid = [];
                foreach (['model' => CatalogCashierOperations::MODEL, 'builder' => CatalogCashierOperations::BUILDER, 'subscription' => CatalogCashierOperations::SUBSCRIPTION] as $kind => $operations) {
                    [$parameters, $required] = $operations[$method] ?? [[], -1];
                    $seen = [];
                    $matches = $required >= 0 && count($node->args) <= count($parameters) && count($node->args) >= $required;
                    foreach ($node->getArgs() as $position => $arg) {
                        $name = $arg->name?->toString() ?? ($parameters[$position] ?? 'unknown');
                        $matches = $matches && ! $arg->unpack && in_array($name, $parameters, true) && ! isset($seen[$name]);
                        $seen[$name] = true;
                    }
                    foreach (array_slice($parameters, 0, max(0, $required)) as $parameter) {
                        $matches = $matches && isset($seen[$parameter]);
                    }
                    $valid[$kind] = $matches;
                }
                $offset = max(0, $node->getStartFilePos());
                $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'cashier-operation', $method, $offset), 'Cashier candidate '.$method, 'cashier-operation',
                    max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner, metadata: ['method' => $method, 'valid' => $valid]);
            }
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
