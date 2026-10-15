<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Stores provider selectors, never OAuth tokens, state, scopes or user data. */
final class SocialiteCatalogExtractor
{
    public const PARAMETERS = ['redirect' => [], 'user' => [], 'userfromtoken' => ['token'], 'refreshtoken' => ['refreshToken']];

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
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Socialite facts reached their AST or memory budget.', max(1, $node->getStartLine()), $owner);

            return;
        }
        if (Node::is($node, 'statement.class-like') || Node::is($node, 'statement.class-method') || Node::is($node, 'statement.function') || Node::is($node, 'expression.closure') || Node::is($node, 'expression.arrow-function')) {
            $owner = $this->owners[$node->getStartFilePos()] ?? $owner;
        }
        if (Node::is($node, 'expression.method-call') && Node::is($node->name, 'identifier') && isset(self::PARAMETERS[$method = strtolower($node->name->toString())]) && ! $node->isFirstClassCallable()) {
            $parameters = self::PARAMETERS[$method];
            $valid = count($node->args) === count($parameters);
            $seen = [];
            foreach ($node->getArgs() as $position => $arg) {
                $name = $arg->name?->toString() ?? ($parameters[$position] ?? 'unknown');
                $valid = $valid && ! $arg->unpack && in_array($name, $parameters, true) && ! isset($seen[$name]);
                $seen[$name] = true;
            }
            $root = $node->var;
            $depth = 0;
            while (Node::is($root, 'expression.method-call') && Node::is($root->name, 'identifier') && in_array(strtolower($root->name->toString()), CatalogSocialiteResolver::FLUENT, true) && $depth++ < 16) {
                $valid = $valid && ! $root->isFirstClassCallable();
                $root = $root->var;
            }
            $driver = null;
            $selectionPresent = false;
            if ((Node::is($root, 'expression.static-call') || Node::is($root, 'expression.method-call')) && Node::is($root->name, 'identifier') && in_array(strtolower($root->name->toString()), ['driver', 'with'], true)) {
                $selectionPresent = true;
                $arg = count($root->args) === 1 ? $root->getArgs()[0] : null;
                $valid = $valid && ! $root->isFirstClassCallable() && $arg !== null && ! $arg->unpack && ($arg->name === null || $arg->name->toString() === 'driver');
                if (Node::is($arg?->value, 'scalar.string') && preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,127}\z/D', $arg->value->value) === 1) {
                    $driver = $arg->value->value;
                }
            }
            $offset = max(0, $node->getStartFilePos());
            $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'socialite-operation', $method, $offset), 'Socialite candidate '.$method,
                'socialite-operation', max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner,
                metadata: ['method' => $method, 'driver' => $driver, 'selection_present' => $selectionPresent, 'valid' => $valid]);
        }
        foreach ($node->getSubNodeNames() as $key) {
            foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) {
                if (Node::is($child, 'node')) {
                    $this->visit($file, $child, $owner);
                }
            }
        }
    }
}
