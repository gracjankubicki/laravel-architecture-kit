<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\DataOperations;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Local source query identity and prefixes; expanded nodes are transient, never serialized. */
final class CatalogQueryBindings
{
    /** @var array<int, Node> */
    private array $calls = [];

    /** @var list<CatalogDiagnostic> */
    private array $diagnostics = [];

    private int $visited = 0;

    private bool $limited = false;

    /** @return array{calls: array<int, Node>, diagnostics: list<CatalogDiagnostic>} */
    public function extract(FileContext $file): array
    {
        $this->calls = $this->diagnostics = [];
        $this->visited = 0;
        $this->limited = false;
        $vars = [];
        foreach ($file->syntax() ?? [] as $node) {
            $this->visit($node, $vars);
        }

        return ['calls' => $this->calls, 'diagnostics' => $this->diagnostics];
    }

    /** @param array<string, array{id: int, value: Node}> $vars */
    private function visit(Node $node, array &$vars): void
    {
        if ($this->limited) {
            return;
        }
        if (++$this->visited > 25000 || $this->visited % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
            $this->limited = true;
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Local query binding reached its AST or memory budget.', max(1, $node->getStartLine()));

            return;
        }
        if (Node::is($node, 'statement.class-like') || Node::is($node, 'statement.class-method') || Node::is($node, 'statement.function') || Node::is($node, 'expression.closure') || Node::is($node, 'expression.arrow-function')) {
            $local = [];
            foreach ($node->getSubNodeNames() as $key) {
                if (! in_array($key, ['stmts', 'expr'], true)) {
                    continue;
                }
                foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) {
                    if (Node::is($child, 'node')) {
                        $this->visit($child, $local);
                    }
                }
            }

            return;
        }
        if (Node::is($node, 'expression.assign-ref')) {
            $this->children($node, $vars);
            if ($vars !== []) {
                $this->diagnostics[] = new CatalogDiagnostic('data_binding_analysis', 'Reference assignment invalidates local query identities.', max(1, $node->getStartLine()));
            }
            $vars = [];

            return;
        }
        if (Node::is($node, 'expression.assign') && Node::is($node->var, 'expression.variable') && is_string($node->var->name)) {
            $alias = Node::is($node->expr, 'expression.variable') && is_string($node->expr->name) ? ($vars[$node->expr->name] ?? null) : null;
            $this->visit($node->expr, $vars);
            $value = $this->calls[spl_object_id($node->expr)] ?? $node->expr;
            // A fluent query assignment retains the receiver's object identity.
            // Read its current prefix after visiting the expression, which may mutate it.
            $receiver = $node->expr;
            while (Node::is($receiver, 'expression.method-call') || Node::is($receiver, 'expression.nullsafe-method-call')) {
                $receiver = $receiver->var;
            }
            if ($this->queryPrefix($value) && Node::is($receiver, 'expression.variable') && is_string($receiver->name)) {
                $alias = $vars[$receiver->name] ?? null;
            }
            if ($alias !== null) {
                $vars[$node->var->name] = $alias;
            } elseif ($value instanceof Node && $this->queryPrefix($value)) {
                $vars[$node->var->name] = ['id' => $node->getStartFilePos(), 'value' => $value];
            } else {
                if (isset($vars[$node->var->name])) {
                    $this->diagnostics[] = new CatalogDiagnostic('data_binding_analysis', 'Assigned query receiver was overwritten by an unresolved value.', max(1, $node->getStartLine()));
                }
                unset($vars[$node->var->name]);
            }

            return;
        }
        if (Node::is($node, 'expression.assign')) {
            $this->children($node, $vars);
            foreach ($this->escapedQueryNames($node->expr) as $name) {
                $this->invalidateAlias($name, $vars, $node);
            }

            return;
        }
        if (Node::is($node, 'statement.if')) {
            $this->visit($node->cond, $vars);
            $entry = $vars;
            $branches = [];
            $branch = $entry;
            foreach ($node->stmts as $stmt) {
                $this->visit($stmt, $branch);
            }
            $branches[] = $branch;
            foreach ($node->elseifs as $elseif) {
                $branch = $entry;
                $this->visit($elseif->cond, $branch);
                foreach ($elseif->stmts as $stmt) {
                    $this->visit($stmt, $branch);
                }
                $branches[] = $branch;
            }
            $branch = $entry;
            foreach ($node->else->stmts ?? [] as $stmt) {
                $this->visit($stmt, $branch);
            }
            $branches[] = $branch;
            $vars = $branches[0];
            foreach ($branches as $branch) {
                foreach ($vars as $name => $value) {
                    if (($branch[$name] ?? null) !== $value) {
                        $this->diagnostics[] = new CatalogDiagnostic('data_binding_analysis', 'Query identity or accumulated constraints differ across source branches.', max(1, $node->getStartLine()));
                        unset($vars[$name]);
                    }
                }
            }

            return;
        }
        if (Node::is($node, 'statement.for') || Node::is($node, 'statement.foreach') || Node::is($node, 'statement.while') || Node::is($node, 'statement.do') || Node::is($node, 'statement.try-catch') || Node::is($node, 'statement.switch')) {
            if ($vars !== []) {
                $this->diagnostics[] = new CatalogDiagnostic('data_binding_analysis', 'Loop, switch or exception query bindings require control-flow composition.', max(1, $node->getStartLine()));
            }
            $local = [];
            $this->children($node, $local);
            $vars = [];

            return;
        }
        if (Node::is($node, 'statement.unset')) {
            foreach ($node->vars as $var) {
                if (Node::is($var, 'expression.variable') && is_string($var->name)) {
                    unset($vars[$var->name]);
                }
            }

            return;
        }
        if (Node::is($node, 'expression.assign-op') || Node::is($node, 'expression.pre-inc') || Node::is($node, 'expression.post-inc') || Node::is($node, 'expression.pre-dec') || Node::is($node, 'expression.post-dec')) {
            $this->children($node, $vars);
            if (Node::is($node->var, 'expression.variable') && is_string($node->var->name)) {
                unset($vars[$node->var->name]);
            }

            return;
        }
        if (Node::is($node, 'expression.method-call') || Node::is($node, 'expression.nullsafe-method-call') || Node::is($node, 'expression.static-call')) {
            // Evaluate receivers and argument expressions before recording the outer call.
            $this->children($node, $vars);
            if ($node->isFirstClassCallable()) {
                return;
            }
            if (! Node::is($node->name, 'identifier')) {
                if (Node::is($node, 'expression.method-call') || Node::is($node, 'expression.nullsafe-method-call')) {
                    $root = $node->var;
                    while (Node::is($root, 'expression.method-call') || Node::is($root, 'expression.nullsafe-method-call')) {
                        $root = $root->var;
                    }
                    if (Node::is($root, 'expression.variable') && is_string($root->name)) {
                        $this->invalidateAlias($root->name, $vars, $node);
                    }
                }
                foreach ($node->getArgs() as $arg) {
                    foreach ($this->escapedQueryNames($arg->value) as $name) {
                        $this->invalidateAlias($name, $vars, $node);
                    }
                }

                return;
            }
            $expanded = $this->expand($node, $vars);
            if ($expanded !== null) {
                $this->calls[spl_object_id($node)] = $expanded;
                $root = $node;
                while (Node::is($root, 'expression.method-call') || Node::is($root, 'expression.nullsafe-method-call')) {
                    $root = $root->var;
                }
                if (Node::is($root, 'expression.variable') && is_string($root->name) && isset($vars[$root->name]) && $this->queryPrefix($expanded)) {
                    $identity = $vars[$root->name]['id'];
                    foreach ($vars as &$binding) {
                        if ($binding['id'] === $identity) {
                            $binding['value'] = $expanded;
                        }
                    }
                    unset($binding);
                }
            }
            foreach ($node->getArgs() as $arg) {
                foreach ($this->escapedQueryNames($arg->value) as $name) {
                    $this->invalidateAlias($name, $vars, $node);
                }
            }

            return;
        }
        $this->children($node, $vars);
        if (Node::is($node, 'expression.func-call') && ! $node->isFirstClassCallable() || Node::is($node, 'expression.new')) {
            foreach ($node->getArgs() as $arg) {
                foreach ($this->escapedQueryNames($arg->value) as $name) {
                    $this->invalidateAlias($name, $vars, $node);
                }
            }
        }
    }

    /** @return list<string> */
    private function escapedQueryNames(Node $value, int $depth = 0): array
    {
        if ($this->limited) {
            return [];
        }
        if ($depth > 64) {
            $this->limited = true;
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Query escape container reached its nesting budget.', max(1, $value->getStartLine()));

            return [];
        }
        if (Node::is($value, 'expression.variable') && is_string($value->name)) {
            return [$value->name];
        }
        if (Node::is($value, 'expression.array')) {
            $names = [];
            foreach ($value->items as $item) {
                if ($item !== null) {
                    array_push($names, ...$this->escapedQueryNames($item->value, $depth + 1));
                }
            }

            return array_values(array_unique($names));
        }

        return [];
    }

    /** @param array<string, array{id: int, value: Node}> $vars */
    private function invalidateAlias(string $name, array &$vars, Node $site): void
    {
        $id = $vars[$name]['id'] ?? null;
        if ($id !== null) {
            $this->diagnostics[] = new CatalogDiagnostic('data_binding_analysis', 'Passing a query object to another call invalidates its unproven local state.', max(1, $site->getStartLine()));
        }
        foreach ($vars as $key => $value) {
            if ($value['id'] === $id) {
                unset($vars[$key]);
            }
        }
    }

    /** @param array<string, array{id: int, value: Node}> $vars */
    private function children(Node $node, array &$vars): void
    {
        foreach ($node->getSubNodeNames() as $key) {
            foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) {
                if (Node::is($child, 'node')) {
                    $this->visit($child, $vars);
                }
            }
        }
    }

    /** @param array<string, array{id: int, value: Node}> $vars */
    private function expand(Node $node, array $vars): ?Node
    {
        if ((Node::is($node, 'expression.method-call') || Node::is($node, 'expression.nullsafe-method-call')) && isset($this->calls[spl_object_id($node->var)])) {
            $copy = $node->withFields(['var' => $this->calls[spl_object_id($node->var)]]);

            return $this->bounded($copy);
        }
        $chain = [];
        $root = $node;
        while (Node::is($root, 'expression.method-call') || Node::is($root, 'expression.nullsafe-method-call')) {
            if (count($chain) >= 64) {
                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Bound query chain reached its expansion budget.', max(1, $node->getStartLine()));

                return null;
            }
            array_unshift($chain, $root);
            $root = $root->var;
        }
        if (Node::is($root, 'expression.variable') && is_string($root->name) && isset($vars[$root->name])) {
            $root = $vars[$root->name]['value'];
            foreach ($chain as $call) {
                $copy = $call->withFields(['var' => $root]);
                $root = $copy;
            }

            return $this->bounded($root);
        }

        return null;
    }

    private function bounded(Node $value): ?Node
    {
        $root = $value;
        $depth = 0;
        while (Node::is($root, 'expression.method-call') || Node::is($root, 'expression.nullsafe-method-call')) {
            if (++$depth > 64) {
                $this->limited = true;
                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Accumulated query chain reached its binding budget.', max(1, $value->getStartLine()));

                return null;
            }
            $root = $root->var;
        }

        return $value;
    }

    private function queryPrefix(Node $value): bool
    {
        if (! (Node::is($value, 'expression.static-call') || Node::is($value, 'expression.method-call') || Node::is($value, 'expression.nullsafe-method-call')) || ! Node::is($value->name, 'identifier') || $value->isFirstClassCallable() || DataOperations::kinds(strtolower($value->name->toString())) !== []) {
            return false;
        }
        $root = $value;
        $depth = 0;
        while (Node::is($root, 'expression.method-call') || Node::is($root, 'expression.nullsafe-method-call')) {
            if (++$depth > 64) {
                return false;
            }
            $root = $root->var;
        }

        return Node::is($root, 'expression.static-call') && Node::is($root->class, 'name') && Node::is($root->name, 'identifier') && ! $root->isFirstClassCallable();
    }
}
