<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

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
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Socialite facts reached their AST or memory budget.', max(1, $node->getStartLine()), $owner);

            return;
        }
        if ($node instanceof Stmt\ClassLike || $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_ || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $owner = $this->owners[$node->getStartFilePos()] ?? $owner;
        }
        if ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier && isset(self::PARAMETERS[$method = strtolower($node->name->toString())]) && ! $node->isFirstClassCallable()) {
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
            while ($root instanceof Expr\MethodCall && $root->name instanceof Node\Identifier && in_array(strtolower($root->name->toString()), CatalogSocialiteResolver::FLUENT, true) && $depth++ < 16) {
                $valid = $valid && ! $root->isFirstClassCallable();
                $root = $root->var;
            }
            $driver = null;
            $selectionPresent = false;
            if (($root instanceof Expr\StaticCall || $root instanceof Expr\MethodCall) && $root->name instanceof Node\Identifier && in_array(strtolower($root->name->toString()), ['driver', 'with'], true)) {
                $selectionPresent = true;
                $arg = count($root->args) === 1 ? $root->getArgs()[0] : null;
                $valid = $valid && ! $root->isFirstClassCallable() && $arg !== null && ! $arg->unpack && ($arg->name === null || $arg->name->toString() === 'driver');
                if ($arg?->value instanceof Node\Scalar\String_ && preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,127}\z/D', $arg->value->value) === 1) {
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
                if ($child instanceof Node) {
                    $this->visit($file, $child, $owner);
                }
            }
        }
    }
}
