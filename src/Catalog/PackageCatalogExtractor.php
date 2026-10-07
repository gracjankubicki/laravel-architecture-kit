<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;

/** Package-local source facts share the current AST; package presence is checked later. */
final class PackageCatalogExtractor
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
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Package facts reached their AST or memory budget.', max(1, $node->getStartLine()), $owner);

            return;
        }
        if ($node instanceof Stmt\ClassLike || $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_ || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $owner = $this->owners[$node->getStartFilePos()]->id ?? $owner;
        }
        if ($node instanceof Stmt\Property) {
            foreach ($node->props as $property) {
                if (! in_array($property->name->toString(), ['tools', 'resources', 'prompts'], true)) {
                    continue;
                }
                $targets = [];
                $groupedTargets = [];
                $toolSearchKey = false;
                $hasGroup = false;
                $unknownKey = false;
                if ($property->default instanceof Expr\Array_ && count($property->default->items) > 128) {
                    $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'MCP member list exceeds the 128-entry source budget.', max(1, $property->getStartLine()), $owner);
                }
                $resolved = ! $node->isStatic() && ! $node->isPrivate() && $property->default instanceof Expr\Array_ && count($property->default->items) <= 128;
                foreach ($property->default instanceof Expr\Array_ && count($property->default->items) <= 128 ? $property->default->items : [] as $item) {
                    $searchKey = $item !== null && $item->key !== null && $this->type($file, $item->key) === 'Laravel\\Mcp\\Server\\Tools\\ToolSearch';
                    $unknownKey = $unknownKey || $item !== null && $item->key !== null && ! $item->key instanceof Scalar\String_ && ! $item->key instanceof Scalar\Int_ && $this->type($file, $item->key) === null;
                    if ($searchKey && $property->name->toString() === 'tools' && $item->value instanceof Expr\Array_) {
                        $resolved = $resolved && ! $hasGroup && ! $item->unpack && ! $item->byRef && count($item->value->items) <= 128;
                        $hasGroup = true;
                        if (count($item->value->items) > 128) {
                            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'MCP ToolSearch group exceeds the 128-entry source budget.', max(1, $property->getStartLine()), $owner);
                        }
                        foreach (count($item->value->items) <= 128 ? $item->value->items : [] as $member) {
                            $target = $member !== null && $member->key === null && ! $member->unpack && ! $member->byRef ? $this->type($file, $member->value) : null;
                            $resolved = $resolved && $target !== null;
                            if ($target !== null && count($groupedTargets) < 128) {
                                $groupedTargets[] = $target;
                            } elseif ($target !== null) {
                                $resolved = false;
                            }
                        }

                        continue;
                    }
                    $toolSearchKey = $toolSearchKey || $searchKey;
                    $target = $item !== null && ! $item->unpack && ! $item->byRef ? $this->type($file, $item->value) : null;
                    $resolved = $resolved && $target !== null;
                    if ($target !== null) {
                        $targets[] = $target;
                    }
                }
                $selector = $unknownKey ? 'unknown-member-key' : ($hasGroup ? 'tool-search-group' : ($toolSearchKey ? 'tool-search-key' : null));
                $this->operation($file, $property, $owner, 'members', $property->name->toString(), $selector, $targets, $resolved && ! ($hasGroup && ($toolSearchKey || $unknownKey)), $groupedTargets);
            }
        }
        if ($node instanceof Node\Attribute && in_array($name = $file->resolvedName($node->name), ['Laravel\\Mcp\\Server\\Attributes\\Name', 'Laravel\\Mcp\\Server\\Attributes\\Description'], true)) {
            $value = null;
            $resolved = count($node->args) === 1;
            foreach ($node->args as $arg) {
                $resolved = $resolved && ! $arg->unpack && ($arg->name === null || $arg->name->toString() === 'value') && $arg->value instanceof Scalar\String_;
                if ($arg->value instanceof Scalar\String_) {
                    $value = $arg->value->value;
                }
            }
            $resolved = $resolved && $value !== null && strlen($value) <= 65536;
            $selector = $name === 'Laravel\\Mcp\\Server\\Attributes\\Name' && $resolved && preg_match('/\A[a-zA-Z0-9_.:\-]{1,128}\z/D', $value) === 1 ? $value : null;
            $resolved = $resolved && ($name !== 'Laravel\\Mcp\\Server\\Attributes\\Name' || $selector !== null);
            $offset = max(0, $node->getStartFilePos());
            $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'package-descriptor', $name, $offset), $selector ?? 'MCP '.substr($name, strrpos($name, '\\') + 1),
                'package-descriptor', max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner,
                metadata: ['package' => 'laravel/mcp', 'attribute' => $name, 'selector' => $selector, 'value_hash' => $resolved ? hash('sha256', $value) : null, 'resolved' => $resolved]);
            if ($value !== null && strlen($value) > 65536) {
                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'MCP attribute value exceeds the source descriptor budget.', max(1, $node->getStartLine()), $owner);
            }
        }
        if ($node instanceof Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier && ! $node->isFirstClassCallable()
            && $file->resolvedName($node->class) === 'Laravel\\Mcp\\Facades\\Mcp' && in_array($method = strtolower($node->name->toString()), ['web', 'local'], true)) {
            $selector = null;
            $targets = [];
            $resolved = count($node->args) === 2;
            $seen = [];
            foreach ($node->getArgs() as $position => $arg) {
                $parameter = $arg->name?->toString() ?? ([$method === 'web' ? 'route' : 'handle', 'serverClass'][$position] ?? 'unknown');
                $resolved = $resolved && ! $arg->unpack && ! isset($seen[$parameter]) && in_array($parameter, [$method === 'web' ? 'route' : 'handle', 'serverClass'], true);
                $seen[$parameter] = true;
                if ($parameter === 'serverClass') {
                    $target = $this->type($file, $arg->value);
                    $resolved = $resolved && $target !== null;
                    if ($target !== null) {
                        $targets[] = $target;
                    }
                } elseif ($arg->value instanceof Scalar\String_ && preg_match('/\A[\/a-zA-Z0-9_.:\-]{1,256}\z/D', $arg->value->value) === 1) {
                    $selector = $arg->value->value;
                } else {
                    $resolved = false;
                }
            }
            $this->operation($file, $node, $owner, $method, 'Laravel\\Mcp\\Facades\\Mcp', $selector, $targets, $resolved && $selector !== null);
        }
        foreach ($node->getSubNodeNames() as $key) {
            foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) {
                if ($child instanceof Node) {
                    $this->visit($file, $child, $owner);
                }
            }
        }
    }

    private function type(FileContext $file, Expr $node): ?string
    {
        if ($node instanceof Scalar\String_) {
            $name = ltrim($node->value, '\\');

            return strlen($name) <= 1000 && preg_match('/\A[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(?:\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*\z/D', $name) === 1 ? $name : null;
        }
        $class = $node instanceof Expr\ClassConstFetch && $node->name instanceof Node\Identifier && strtolower($node->name->toString()) === 'class' ? $node->class : null;

        return $class instanceof Node\Name && strlen($name = $file->resolvedName($class)) <= 1000 ? $name : null;
    }

    /** @param list<string> $targets
     * @param  list<string>  $groupedTargets
     */
    private function operation(FileContext $file, Node $node, string $owner, string $method, string $receiver, ?string $selector, array $targets, bool $resolved, array $groupedTargets = []): void
    {
        $offset = max(0, $node->getStartFilePos());
        $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'package-operation', 'mcp:'.$method, $offset), 'MCP '.$method, 'package-operation',
            max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner,
            metadata: ['package' => 'laravel/mcp', 'method' => $method, 'receiver' => $receiver, 'selector' => $selector,
                'targets' => array_values(array_unique($targets)), 'resolved' => $resolved, 'execution_proven' => false,
                'grouped_targets' => $groupedTargets]);
    }
}
