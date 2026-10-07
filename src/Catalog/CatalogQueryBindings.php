<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\DataOperations;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/** Local source query identity and prefixes; expanded nodes are transient, never serialized. */
final class CatalogQueryBindings
{
    /** @var array<int, Expr> */
    private array $calls = [];

    /** @var list<CatalogDiagnostic> */
    private array $diagnostics = [];

    private int $visited = 0;

    private bool $limited = false;

    /** @return array{calls: array<int, Expr>, diagnostics: list<CatalogDiagnostic>} */
    public function extract(FileContext $file): array
    {
        $this->calls = $this->diagnostics = [];
        $this->visited = 0;
        $this->limited = false;
        $vars = [];
        foreach ($file->ast() ?? [] as $node) {
            $this->visit($node, $vars);
        }

        return ['calls' => $this->calls, 'diagnostics' => $this->diagnostics];
    }

    /** @param array<string, array{id: int, value: Expr}> $vars */
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
        if ($node instanceof Stmt\ClassLike || $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_ || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $local = [];
            foreach ($node->getSubNodeNames() as $key) {
                if (! in_array($key, ['stmts', 'expr'], true)) {
                    continue;
                }
                foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) {
                    if ($child instanceof Node) {
                        $this->visit($child, $local);
                    }
                }
            }

            return;
        }
        if ($node instanceof Expr\AssignRef) {
            $this->children($node, $vars);
            if ($vars !== []) {
                $this->diagnostics[] = new CatalogDiagnostic('data_binding_analysis', 'Reference assignment invalidates local query identities.', max(1, $node->getStartLine()));
            }
            $vars = [];

            return;
        }
        if ($node instanceof Expr\Assign && $node->var instanceof Expr\Variable && is_string($node->var->name)) {
            $alias = $node->expr instanceof Expr\Variable && is_string($node->expr->name) ? ($vars[$node->expr->name] ?? null) : null;
            $this->visit($node->expr, $vars);
            $value = $this->calls[spl_object_id($node->expr)] ?? $node->expr;
            // A fluent query assignment retains the receiver's object identity.
            // Read its current prefix after visiting the expression, which may mutate it.
            $receiver = $node->expr;
            while ($receiver instanceof Expr\MethodCall || $receiver instanceof Expr\NullsafeMethodCall) {
                $receiver = $receiver->var;
            }
            if ($this->queryPrefix($value) && $receiver instanceof Expr\Variable && is_string($receiver->name)) {
                $alias = $vars[$receiver->name] ?? null;
            }
            if ($alias !== null) {
                $vars[$node->var->name] = $alias;
            } elseif ($this->queryPrefix($value)) {
                $vars[$node->var->name] = ['id' => $node->getStartFilePos(), 'value' => $value];
            } else {
                if (isset($vars[$node->var->name])) {
                    $this->diagnostics[] = new CatalogDiagnostic('data_binding_analysis', 'Assigned query receiver was overwritten by an unresolved value.', max(1, $node->getStartLine()));
                }
                unset($vars[$node->var->name]);
            }

            return;
        }
        if ($node instanceof Expr\Assign) {
            $this->children($node, $vars);
            foreach ($this->escapedQueryNames($node->expr) as $name) {
                $this->invalidateAlias($name, $vars, $node);
            }

            return;
        }
        if ($node instanceof Stmt\If_) {
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
        if ($node instanceof Stmt\For_ || $node instanceof Stmt\Foreach_ || $node instanceof Stmt\While_ || $node instanceof Stmt\Do_ || $node instanceof Stmt\TryCatch || $node instanceof Stmt\Switch_) {
            if ($vars !== []) {
                $this->diagnostics[] = new CatalogDiagnostic('data_binding_analysis', 'Loop, switch or exception query bindings require control-flow composition.', max(1, $node->getStartLine()));
            }
            $local = [];
            $this->children($node, $local);
            $vars = [];

            return;
        }
        if ($node instanceof Stmt\Unset_) {
            foreach ($node->vars as $var) {
                if ($var instanceof Expr\Variable && is_string($var->name)) {
                    unset($vars[$var->name]);
                }
            }

            return;
        }
        if ($node instanceof Expr\AssignOp || $node instanceof Expr\PreInc || $node instanceof Expr\PostInc || $node instanceof Expr\PreDec || $node instanceof Expr\PostDec) {
            $this->children($node, $vars);
            if ($node->var instanceof Expr\Variable && is_string($node->var->name)) {
                unset($vars[$node->var->name]);
            }

            return;
        }
        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall || $node instanceof Expr\StaticCall) {
            // Evaluate receivers and argument expressions before recording the outer call.
            $this->children($node, $vars);
            if ($node->isFirstClassCallable()) {
                return;
            }
            if (! $node->name instanceof Node\Identifier) {
                if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) {
                    $root = $node->var;
                    while ($root instanceof Expr\MethodCall || $root instanceof Expr\NullsafeMethodCall) {
                        $root = $root->var;
                    }
                    if ($root instanceof Expr\Variable && is_string($root->name)) {
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
                while ($root instanceof Expr\MethodCall || $root instanceof Expr\NullsafeMethodCall) {
                    $root = $root->var;
                }
                if ($root instanceof Expr\Variable && is_string($root->name) && isset($vars[$root->name]) && $this->queryPrefix($expanded)) {
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
        if ($node instanceof Expr\FuncCall && ! $node->isFirstClassCallable() || $node instanceof Expr\New_) {
            foreach ($node->getArgs() as $arg) {
                foreach ($this->escapedQueryNames($arg->value) as $name) {
                    $this->invalidateAlias($name, $vars, $node);
                }
            }
        }
    }

    /** @return list<string> */
    private function escapedQueryNames(Expr $value, int $depth = 0): array
    {
        if ($this->limited) {
            return [];
        }
        if ($depth > 64) {
            $this->limited = true;
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Query escape container reached its nesting budget.', max(1, $value->getStartLine()));

            return [];
        }
        if ($value instanceof Expr\Variable && is_string($value->name)) {
            return [$value->name];
        }
        if ($value instanceof Expr\Array_) {
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

    /** @param array<string, array{id: int, value: Expr}> $vars */
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

    /** @param array<string, array{id: int, value: Expr}> $vars */
    private function children(Node $node, array &$vars): void
    {
        foreach ($node->getSubNodeNames() as $key) {
            foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) {
                if ($child instanceof Node) {
                    $this->visit($child, $vars);
                }
            }
        }
    }

    /** @param array<string, array{id: int, value: Expr}> $vars */
    private function expand(Expr $node, array $vars): ?Expr
    {
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) && isset($this->calls[spl_object_id($node->var)])) {
            $copy = clone $node;
            $copy->var = $this->calls[spl_object_id($node->var)];

            return $this->bounded($copy);
        }
        $chain = [];
        $root = $node;
        while ($root instanceof Expr\MethodCall || $root instanceof Expr\NullsafeMethodCall) {
            if (count($chain) >= 64) {
                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Bound query chain reached its expansion budget.', max(1, $node->getStartLine()));

                return null;
            }
            array_unshift($chain, $root);
            $root = $root->var;
        }
        if ($root instanceof Expr\Variable && is_string($root->name) && isset($vars[$root->name])) {
            $root = $vars[$root->name]['value'];
            foreach ($chain as $call) {
                $copy = clone $call;
                $copy->var = $root;
                $root = $copy;
            }

            return $this->bounded($root);
        }

        return null;
    }

    private function bounded(Expr $value): ?Expr
    {
        $root = $value;
        $depth = 0;
        while ($root instanceof Expr\MethodCall || $root instanceof Expr\NullsafeMethodCall) {
            if (++$depth > 64) {
                $this->limited = true;
                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Accumulated query chain reached its binding budget.', max(1, $value->getStartLine()));

                return null;
            }
            $root = $root->var;
        }

        return $value;
    }

    private function queryPrefix(Expr $value): bool
    {
        if (! ($value instanceof Expr\StaticCall || $value instanceof Expr\MethodCall || $value instanceof Expr\NullsafeMethodCall) || ! $value->name instanceof Node\Identifier || $value->isFirstClassCallable() || DataOperations::kinds(strtolower($value->name->toString())) !== []) {
            return false;
        }
        $root = $value;
        $depth = 0;
        while ($root instanceof Expr\MethodCall || $root instanceof Expr\NullsafeMethodCall) {
            if (++$depth > 64) {
                return false;
            }
            $root = $root->var;
        }

        return $root instanceof Expr\StaticCall && $root->class instanceof Node\Name && $root->name instanceof Node\Identifier && ! $root->isFirstClassCallable();
    }
}
