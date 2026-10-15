<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Bounded straight-line identity tracking; escapes and control flow discard alias certainty. */
final class CatalogScoutBuilderState
{
    public const FLUENT = ['within', 'where', 'wherein', 'wherenotin', 'withtrashed', 'onlytrashed', 'take', 'orderby', 'orderbydesc', 'latest', 'oldest', 'semantic', 'hybrid', 'options', 'query', 'withrawresults'];

    /** @var array<string, int> */
    private array $variables = [];

    /** @var array<int, array<string, mixed>> */
    private array $builders = [];

    /** @var array<int, array<string, array<string, mixed>>> */
    private array $snapshots = [];

    private int $visited = 0;

    public bool $limited = false;

    /** @param array<int, array<string, array<string, mixed>>> $sites
     * @param  array<int, string>  $owners
     */
    public function __construct(private readonly array $sites, private readonly array $owners) {}

    /** @param list<Node> $nodes
     * @return array<int, array<string, array<string, mixed>>>
     */
    public function extract(array $nodes): array
    {
        foreach ($nodes as $node) {
            $this->statement($node);
        }

        return $this->snapshots;
    }

    private function room(): bool
    {
        if (++$this->visited > 25000 || count($this->variables) > 128 || count($this->builders) > 128 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->limited = true;
            $this->variables = $this->builders = [];

            return false;
        }

        return true;
    }

    private function statement(Node $node): void
    {
        if (! $this->room()) {
            return;
        }
        if (Node::is($node, 'statement.expression') || Node::is($node, 'statement.return')) {
            if ($node->expr !== null) {
                $this->expression($node->expr);
            }

            return;
        }
        if (Node::is($node, 'statement.namespace') || Node::is($node, 'statement.class-like') || Node::is($node, 'statement.class-method') || Node::is($node, 'statement.function')) {
            $savedVariables = $this->variables;
            $savedBuilders = $this->builders;
            $this->variables = $this->builders = [];
            foreach ($node->stmts ?? [] as $child) {
                $this->statement($child);
            }
            $this->variables = $savedVariables;
            $this->builders = $savedBuilders;

            return;
        }
        // Branches, loops, exceptions and other statements may mutate or escape any builder.
        $this->variables = $this->builders = [];
    }

    private function expression(Node $node): ?int
    {
        if (! $this->room()) {
            return null;
        }
        if (Node::is($node, 'expression.variable') && is_string($node->name)) {
            $id = $this->variables[$node->name] ?? null;

            return $id !== null && isset($this->builders[$id]) ? $id : null;
        }
        if (Node::is($node, 'expression.assign') && Node::is($node->var, 'expression.variable') && is_string($node->var->name)) {
            $id = $this->expression($node->expr);
            unset($this->variables[$node->var->name]);
            if ($id !== null) {
                $this->variables[$node->var->name] = $id;
            }

            return $id;
        }
        if (Node::is($node, 'expression.static-call') && Node::is($node->name, 'identifier') && strtolower($node->name->toString()) === 'search' && ! $node->isFirstClassCallable()) {
            $id = max(0, $node->getStartFilePos());
            $site = $this->sites[$id]['search'] ?? null;
            if ($site !== null) {
                $this->builders[$id] = $site;

                return $id;
            }

            return null;
        }
        if (Node::is($node, 'expression.method-call') && Node::is($node->name, 'identifier') && ! $node->isFirstClassCallable()) {
            $id = $this->expression($node->var);
            if ($id === null) {
                $this->variables = $this->builders = [];

                return null;
            }
            $method = strtolower($node->name->toString());
            if (in_array($method, self::FLUENT, true)) {
                if ($method === 'within') {
                    $this->builders[$id]['index_override_supplied'] = true;
                    $this->builders[$id]['index_override'] = null;
                    $arg = count($node->args) === 1 ? $node->getArgs()[0] : null;
                    $this->builders[$id]['valid'] = $this->builders[$id]['valid'] && $arg !== null && ! $arg->unpack && ($arg->name === null || $arg->name->toString() === 'index');
                    if (Node::is($arg?->value, 'scalar.string') && preg_match('/\A[a-zA-Z0-9_][a-zA-Z0-9_.:\-]{0,255}\z/D', $arg->value->value) === 1) {
                        $this->builders[$id]['index_override'] = $arg->value->value;
                    }
                }
                if (in_array($method, ['query', 'withrawresults'], true)) {
                    $this->callback($id, $method, $node);
                } else {
                    foreach ($node->getArgs() as $argument) {
                        if (! Node::is($argument->value, 'scalar') && ! Node::is($argument->value, 'expression.const-fetch')) {
                            // Nonliteral fluent arguments may run application code before the terminal.
                            $this->builders[$id]['callbacks_resolved'] = false;
                        }
                    }
                }

                return $id;
            }
            $site = $this->sites[max(0, $node->getStartFilePos())][$method] ?? null;
            if ($site !== null && $method !== 'search' && ! in_array($method, ['searchable', 'unsearchable', 'searchablesync', 'unsearchablesync', 'removeallfromsearch'], true)) {
                $this->snapshots[max(0, $node->getStartFilePos())][$method] = [...$site,
                    'valid' => $site['valid'] && $this->builders[$id]['valid'], 'root_offset' => $id,
                    'index_override_supplied' => $this->builders[$id]['index_override_supplied'], 'index_override' => $this->builders[$id]['index_override'],
                    'callbacks' => $this->builders[$id]['callbacks'], 'callbacks_resolved' => $this->builders[$id]['callbacks_resolved']];
                // An invoked callback may mutate any captured builder before the next statement.
                if ($this->builders[$id]['callbacks'] !== [] || ! $this->builders[$id]['callbacks_resolved']) {
                    $this->variables = $this->builders = [];
                }

                return null;
            }
            unset($this->builders[$id]);

            return null;
        }
        if (! Node::is($node, 'scalar') && ! Node::is($node, 'expression.const-fetch') && ! Node::is($node, 'expression.class-const-fetch')) {
            $this->variables = $this->builders = [];
        }

        return null;
    }

    private function callback(int $id, string $hook, Node $call): void
    {
        $this->builders[$id]['callbacks'] = array_values(array_filter($this->builders[$id]['callbacks'], fn ($callback) => $callback['hook'] !== $hook));
        $arg = count($call->args) === 1 ? $call->getArgs()[0] : null;
        if ($arg === null || $arg->unpack || $arg->name !== null && $arg->name->toString() !== 'callback') {
            $this->builders[$id]['valid'] = false;
            $this->builders[$id]['callbacks_resolved'] = false;

            return;
        }
        $value = $arg->value;
        if ((Node::is($value, 'expression.closure') || Node::is($value, 'expression.arrow-function')) && isset($this->owners[$value->getStartFilePos()])) {
            $this->builders[$id]['callbacks'][] = ['target' => $this->owners[$value->getStartFilePos()], 'hook' => $hook];
        } elseif (! (Node::is($value, 'expression.const-fetch') && strtolower($value->name->toString()) === 'null')) {
            $this->builders[$id]['callbacks_resolved'] = false;
        }
    }
}
