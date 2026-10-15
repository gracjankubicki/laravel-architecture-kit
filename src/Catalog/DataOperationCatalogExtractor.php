<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\DataOperations;
use GracjanKubicki\ArchitectureKit\Impact\DataSql;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Local terminal operations. Model table selectors are resolved in the current snapshot. */
final class DataOperationCatalogExtractor
{
    /** @var list<CatalogElement> */
    private array $elements = [];

    /** @var list<CatalogDiagnostic> */
    private array $diagnostics = [];

    /** @var array<int, CatalogElement> */
    private array $owners = [];

    /** @var array<int, Node> */
    private array $boundCalls = [];

    private int $visited = 0;

    private bool $limited = false;

    public function extract(FileContext $file, CatalogFacts $php): CatalogFacts
    {
        $this->elements = $this->diagnostics = $this->owners = [];
        $bindings = (new CatalogQueryBindings)->extract($file);
        $this->boundCalls = $bindings['calls'];
        $this->diagnostics = $bindings['diagnostics'];
        $this->visited = 0;
        $this->limited = false;
        foreach ($php->elements as $element) {
            if (in_array($element->kind, ['class', 'method', 'function', 'closure'], true)) {
                $this->owners[$element->offset] = $element;
            }
        }
        $owner = CatalogElement::identity($file->path, 'file', $file->path);
        foreach ($file->syntax() ?? [] as $node) {
            $this->visit($file, $node, $owner, '');
        }

        return new CatalogFacts($file->path, $this->elements, [], $this->diagnostics);
    }

    private function visit(FileContext $file, Node $node, string $owner, string $class): void
    {
        if ($this->limited) {
            return;
        }
        if (++$this->visited > 25000 || $this->visited % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
            $this->limited = true;
            $this->diagnostics[] = new CatalogDiagnostic('data_limit', 'Data operations reached their AST or memory budget.', max(1, $node->getStartLine()), $owner);

            return;
        }
        if (isset($this->owners[$node->getStartFilePos()]) && (Node::is($node, 'statement.class-like') || Node::is($node, 'statement.class-method') || Node::is($node, 'statement.function') || Node::is($node, 'expression.closure') || Node::is($node, 'expression.arrow-function'))) {
            $declaration = $this->owners[$node->getStartFilePos()];
            $owner = $declaration->id;
            if (Node::is($node, 'statement.class-like')) {
                $class = $declaration->name;
            }
        }
        if ((Node::is($node, 'expression.static-call') || Node::is($node, 'expression.method-call') || Node::is($node, 'expression.nullsafe-method-call'))
            && Node::is($node->name, 'identifier') && ! $node->isFirstClassCallable()) {
            $site = $this->boundCalls[spl_object_id($node)] ?? $node;
            if (Node::is($site, 'expression.static-call') || Node::is($site, 'expression.method-call') || Node::is($site, 'expression.nullsafe-method-call')) {
                $this->operation($file, $site, $owner, $class);
            }
        }
        foreach ($node->getSubNodeNames() as $key) {
            $value = $node->$key;
            foreach (is_array($value) ? $value : [$value] as $child) {
                if (Node::is($child, 'node')) {
                    $this->visit($file, $child, $owner, $class);
                }
            }
        }
    }

    private function operation(FileContext $file, Node $site, string $owner, string $class): void
    {
        if (! Node::is($site->name, 'identifier')) {
            return;
        }
        $chain = [];
        $root = $site;
        while (Node::is($root, 'expression.method-call') || Node::is($root, 'expression.nullsafe-method-call')) {
            if (count($chain) >= 64 || ! Node::is($root->name, 'identifier') || $root->isFirstClassCallable()) {
                return;
            }
            array_unshift($chain, $root);
            $root = $root->var;
        }
        if (! Node::is($root, 'expression.static-call') || ! Node::is($root->class, 'name') || ! Node::is($root->name, 'identifier') || $root->isFirstClassCallable()) {
            return;
        }
        array_unshift($chain, $root);
        $receiver = in_array(strtolower($root->class->toString()), ['self', 'static'], true) ? $class : $file->resolvedName($root->class);
        $method = strtolower($site->name->toString());
        $db = strcasecmp($receiver, 'Illuminate\\Support\\Facades\\DB') === 0;
        $schema = strcasecmp($receiver, 'Illuminate\\Support\\Facades\\Schema') === 0;
        if ($db || $schema) {
            $receiver = $db ? 'Illuminate\\Support\\Facades\\DB' : 'Illuminate\\Support\\Facades\\Schema';
        }
        $kinds = DataOperations::kinds($method);
        if ($schema) {
            $kinds = in_array($method, ['create', 'table', 'drop', 'dropifexists', 'dropcolumns', 'rename'], true) ? ['schema']
                : (in_array($method, ['hastable', 'hascolumn', 'hascolumns', 'gettables', 'getcolumns'], true) ? ['schema-read'] : []);
        }
        $sql = $db && in_array($method, ['select', 'selectone', 'cursor', 'insert', 'update', 'delete', 'statement', 'unprepared', 'affectingstatement'], true)
            && ! array_filter($chain, fn ($call) => in_array(strtolower($call->name->toString()), ['table', 'from'], true));
        if ($kinds === [] && ! $sql) {
            return;
        }
        $tables = [];
        $connection = ['kind' => 'default', 'name' => null];
        $explicitConnection = false;
        $unsupported = [];
        $conditions = [];
        $excludedScopes = ['all' => false, 'keys' => [], 'resolved' => true];
        foreach ($chain as $call) {
            $name = strtolower($call->name->toString());
            if (Node::is($call, 'expression.nullsafe-method-call')) {
                $conditions[] = 'Receiver must be non-null.';
            }
            if (in_array($name, ['withoutglobalscope', 'withoutglobalscopes'], true)) {
                $argument = $this->argument($call, 0, $name === 'withoutglobalscope' ? 'scope' : 'scopes');
                if ($name === 'withoutglobalscopes' && ($call->getArgs() === [] || Node::is($argument, 'expression.const-fetch') && strtolower($argument->name->toString()) === 'null')) {
                    $excludedScopes['all'] = true;
                } else {
                    $values = $name === 'withoutglobalscopes' && Node::is($argument, 'expression.array') ? $argument->items : [$argument];
                    if (count($values) > 128) {
                        $values = [null];
                    }
                    foreach ($values as $value) {
                        $node = Node::is($value, 'array-item') ? ($value->unpack ? null : $value->value) : $value;
                        $key = Node::is($node, 'expression.class-const-fetch') && Node::is($node->class, 'name') && Node::is($node->name, 'identifier') && strtolower($node->name->toString()) === 'class'
                            ? $file->resolvedName($node->class) : (Node::is($node, 'scalar.string') ? $node->value : null);
                        if ($key === null || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_.\\\\-]{0,499}\z/D', $key) !== 1) {
                            $excludedScopes['resolved'] = false;
                        } else {
                            $excludedScopes['keys'][] = $key;
                        }
                    }
                }
            }
            if (array_filter($call->getArgs(), fn ($arg) => $arg->unpack)) {
                $unsupported[] = 'unpacked-arguments';
            }
            if (in_array($name, ['connection', 'on', 'setconnection'], true)) {
                $explicitConnection = true;
                $value = $this->string($this->argument($call, 0, $name === 'connection' ? 'name' : 'connection'));
                $value = is_string($value) && preg_match('/\\A[a-zA-Z0-9_.-]{1,256}\\z/D', $value) === 1 ? $value : null;
                $connection = ['kind' => $value === null ? 'dynamic' : 'named', 'name' => $value];
            }
            if (in_array($name, ['table', 'from', 'settable'], true) || $schema && $call === $site) {
                $tables = [['table' => $this->table($this->argument($call, 0, 'table')), 'role' => 'primary']];
                if ($name === 'rename') {
                    $tables[] = ['table' => $this->table($this->argument($call, 1, 'to')), 'role' => 'primary'];
                }
            } elseif (in_array($name, ['join', 'leftjoin', 'rightjoin', 'crossjoin', 'joinwhere', 'leftjoinwhere'], true)) {
                $tables[] = ['table' => $this->table($this->argument($call, 0, 'table')), 'role' => 'read'];
            } elseif ($call !== $site && ! in_array($name, ['connection', 'query', 'on', 'where', 'orwhere', 'wherein', 'wherenotin', 'wherecolumn', 'wherenull', 'wherenotnull', 'wherebetween', 'orderby', 'orderbydesc', 'groupby', 'having', 'select', 'addselect', 'distinct', 'limit', 'take', 'skip', 'offset', 'lockforupdate', 'sharedlock', 'withoutglobalscopes', 'withoutglobalscope', 'withtrashed', 'onlytrashed', 'withouttrashed', 'reorder', 'latest', 'oldest', 'wherejsoncontains', 'wheredate', 'wheretime', 'wheremonth', 'whereyear', 'wherekey', 'wherekeynot'], true)) {
                $unsupported[] = $name;
            }
        }
        if ($sql) {
            $query = $this->string($this->argument($site, 0, 'query'));
            $parsed = $query === null ? ['effects' => [], 'unknown' => true] : (new DataSql)->inspect($query);
            $tables = [];
            foreach ($parsed['effects'] as $effect) {
                if (strlen($effect['table']) > 256) {
                    $parsed['unknown'] = true;

                    continue;
                }
                $tables[] = ['table' => $effect['table'], 'role' => $effect['kind']];
            }
            $kinds = [];
            if ($parsed['unknown']) {
                $unsupported[] = 'partial-sql';
            }
        }
        if (in_array($method, ['cursor', 'lazy', 'lazybyid'], true)) {
            $conditions[] = 'Lazy query executes only when consumed.';
        }
        if (in_array($method, DataOperations::BOTH, true)) {
            $conditions[] = 'Read/write branches depend on matching records and database constraints.';
        }
        $id = CatalogElement::identity($file->path, 'data-operation', $method, $site->getStartFilePos());
        $this->elements[] = new CatalogElement($id, $method, 'data-operation', max(1, $site->getStartLine()), max(1, $site->getEndLine()),
            max(0, $site->getStartFilePos()), $owner, metadata: ['receiver' => $receiver, 'operation' => $method, 'chain_methods' => array_map(fn ($call) => strtolower($call->name->toString()), $chain), 'tables' => $tables,
                'connection' => $connection, 'explicit_connection' => $explicitConnection, 'kinds' => $kinds, 'sql' => $sql,
                'excluded_global_scopes' => $excludedScopes, 'unsupported' => array_values(array_unique($unsupported)), 'conditions' => array_values(array_unique($conditions)), 'execution_proven' => false]);
    }

    private function argument(Node $call, int $position, string $name): ?Node
    {
        foreach ($call->getArgs() as $index => $argument) {
            if (! $argument->unpack && ($argument->name?->toString() === $name || $argument->name === null && $index === $position)) {
                return $argument->value;
            }
        }

        return null;
    }

    private function string(?Node $node, int $depth = 0): ?string
    {
        if ($depth > 16) {
            return null;
        }
        if (Node::is($node, 'scalar.string')) {
            return strlen($node->value) <= 100000 ? $node->value : null;
        }
        if (Node::is($node, 'expression.binary-op.concat')) {
            $left = $this->string($node->left, $depth + 1);
            $right = $this->string($node->right, $depth + 1);

            return $left !== null && $right !== null && strlen($left) + strlen($right) <= 100000 ? $left.$right : null;
        }

        return null;
    }

    private function table(?Node $node): ?string
    {
        $value = $this->string($node);
        $name = $value === null ? null : preg_split('/\s+(?:as\s+)?/i', trim($value))[0];

        return is_string($name) && strlen($name) <= 256 && preg_match('/\A[a-zA-Z_][a-zA-Z0-9_$]*(?:\.[a-zA-Z_][a-zA-Z0-9_$]*)*\z/D', $name) === 1 ? $name : null;
    }
}
