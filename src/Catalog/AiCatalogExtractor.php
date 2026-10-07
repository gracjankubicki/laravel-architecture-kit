<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\Rules\LaravelAi\AiSymbolResolver;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/** Reuses AiSymbolResolver on the current cached AST, with external file lookup disabled. */
final class AiCatalogExtractor
{
    /** @var list<CatalogElement> */
    private array $elements = [];

    /** @var list<CatalogDiagnostic> */
    private array $diagnostics = [];

    /** @var array<int, CatalogElement> */
    private array $owners = [];

    /** @var array<string, CatalogElement> */
    private array $declarations = [];

    private int $visited = 0;

    private int $classes = 0;

    public function extract(FileContext $file, CatalogFacts $php): CatalogFacts
    {
        $this->elements = $this->diagnostics = $this->owners = $this->declarations = [];
        $this->visited = $this->classes = 0;
        foreach ($php->elements as $element) {
            $this->owners[$element->offset] = $element;
            $this->declarations[$element->id] = $element;
        }
        // No basePath: the audit resolver may inspect this AST but cannot open another file.
        $symbols = new AiSymbolResolver;
        foreach ($file->ast() ?? [] as $node) {
            $this->visit($file, $node, CatalogElement::identity($file->path, 'file', $file->path), $symbols);
        }

        return new CatalogFacts($file->path, $this->elements, [], $this->diagnostics);
    }

    private function visit(FileContext $file, Node $node, string $owner, AiSymbolResolver $symbols, bool $conditional = false): void
    {
        if ($this->visited >= 25000) {
            return;
        }
        if (++$this->visited >= 25000 || $this->visited % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
            $this->visited = 25000;
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'AI source facts reached their AST or memory budget.', max(1, $node->getStartLine()), $owner);

            return;
        }
        if ($node instanceof Stmt\ClassLike || $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_ || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $owner = $this->owners[$node->getStartFilePos()]->id ?? $owner;
            $conditional = false;
        }
        $conditional = $conditional || $node instanceof Stmt\If_ || $node instanceof Stmt\ElseIf_ || $node instanceof Stmt\Else_
            || $node instanceof Stmt\Switch_ || $node instanceof Stmt\Case_ || $node instanceof Stmt\For_ || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\While_ || $node instanceof Stmt\Do_ || $node instanceof Stmt\TryCatch;
        $declaration = $this->declarations[$owner] ?? null;
        if ($node instanceof Stmt\Class_ && $declaration?->kind === 'class') {
            if (++$this->classes <= 64) {
                $agent = $symbols->isAgent($declaration->name, $file);
                $tool = $symbols->isTool($declaration->name, $file);
                if ($agent || $tool) {
                    $offset = max(0, $node->getStartFilePos());
                    $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'ai-declaration', $declaration->name, $offset), 'AI '.$declaration->name, 'ai-declaration',
                        max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner, metadata: ['agent' => $agent, 'tool' => $tool]);
                }
            } elseif ($this->classes === 65) {
                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Local AI symbol proofs exceed their 64-class source budget.', max(1, $node->getStartLine()), $owner);
            }
        }
        $toolsMethod = $declaration?->kind === 'method' && str_ends_with(strtolower($declaration->name), '::tools');
        $traitMethod = $declaration?->kind === 'method' && ($this->declarations[$declaration->parent ?? ''] ?? null)?->kind === 'trait';
        if ($node instanceof Stmt\Return_ && $node->expr !== null && ($toolsMethod || $traitMethod && $node->expr instanceof Expr\Array_)) {
            $targets = [];
            $resolved = $node->expr instanceof Expr\Array_ && count($node->expr->items) <= 128;
            foreach ($node->expr instanceof Expr\Array_ && count($node->expr->items) <= 128 ? $node->expr->items : [] as $item) {
                $type = $item !== null && ! $item->unpack && ! $item->byRef && $item->value instanceof Expr\New_ && $item->value->class instanceof Node\Name
                    ? $file->resolvedName($item->value->class) : null;
                $resolved = $resolved && $type !== null && strlen($type) <= 1000;
                if ($type !== null && strlen($type) <= 1000) {
                    $targets[] = $type;
                }
            }
            $offset = max(0, $node->getStartFilePos());
            $kind = $toolsMethod ? 'ai-tools' : 'source-method-object-list';
            $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, $kind, 'objects', $offset), $toolsMethod ? 'AI tools' : 'Returned source object list', $kind,
                max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner,
                metadata: ['targets' => array_values(array_unique($targets)), 'resolved' => $resolved, 'conditional' => $conditional]);
            if ($node->expr instanceof Expr\Array_ && count($node->expr->items) > 128) {
                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'AI tools exceed their 128-member source budget.', max(1, $node->getStartLine()), $owner);
            }
        }
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall) && $node->name instanceof Node\Identifier
            && ! $node->isFirstClassCallable() && in_array($method = strtolower($node->name->toString()), [...CatalogAiGateways::METHODS, ...CatalogAiGateways::SETTERS, ...CatalogAiGateways::GETTERS], true)) {
            $arguments = [];
            foreach (array_slice($node->getArgs(), 0, 128) as $arg) {
                $callback = $arg->value instanceof Expr\Closure || $arg->value instanceof Expr\ArrowFunction ? ($this->owners[$arg->value->getStartFilePos()] ?? null) : null;
                $arguments[] = ['name' => $arg->name?->toString(), 'unpack' => $arg->unpack, 'by_ref' => $arg->byRef,
                    'callback' => $callback?->kind === 'closure' ? $callback->id : null];
            }
            $offset = max(0, $node->getStartFilePos());
            $endOffset = max(0, $node->getEndFilePos());
            $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'ai-gateway-call-site', $method.':'.$endOffset, $offset), 'Source gateway '.$method, 'ai-gateway-call-site',
                max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner,
                metadata: ['method' => $method, 'arguments' => $arguments, 'limited' => count($node->args) > 128, 'end_offset' => $endOffset]);
        }
        if (($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall) && $node->name instanceof Node\Identifier
            && ! $node->isFirstClassCallable() && in_array($method = strtolower($node->name->toString()), CatalogAiOperations::METHODS, true)) {
            $parameters = CatalogAiOperations::invocationParameters($method) ?? [];
            $valid = count($node->args) >= 1 && count($node->args) <= count($parameters);
            $seen = [];
            $attachments = null;
            foreach ($node->getArgs() as $position => $arg) {
                $parameter = $arg->name?->toString() ?? ($parameters[$position] ?? 'unknown');
                $valid = $valid && ! $arg->unpack && in_array($parameter, $parameters, true) && ! isset($seen[$parameter]);
                $seen[$parameter] = true;
                if ($parameter === 'attachments') {
                    $attachments = $arg->value;
                }
            }
            $valid = $valid && isset($seen['prompt']) && (! str_starts_with($method, 'broadcast') || isset($seen['channels']));
            $offset = max(0, $node->getStartFilePos());
            $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, 'ai-call-site', $method, $offset), 'AI '.$method.' source call', 'ai-call-site',
                max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner,
                metadata: ['method' => $method, 'valid' => $valid, 'attachments_present' => isset($seen['attachments']), 'end_offset' => max(0, $node->getEndFilePos()), 'attachments' => CatalogAiAttachments::extract($file, $attachments)]);
        }
        if ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier && ! $node->isFirstClassCallable()
            && in_array($callbackMethod = strtolower($node->name->toString()), ['each', 'then', 'catch'], true)) {
            $root = $node->var;
            $depth = 0;
            $chain = [$callbackMethod];
            $forms = [$this->callbackForm($node)];
            $chainEnds = [max(0, $node->getEndFilePos())];
            $chainValid = $this->callbackShape($node);
            while ($root instanceof Expr\MethodCall && $root->name instanceof Node\Identifier
                && in_array(strtolower($root->name->toString()), ['each', 'then', 'catch'], true)) {
                if (++$depth >= 16) {
                    $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Possible AI response callback chain exceeds its 16-call source budget.', max(1, $node->getStartLine()), $owner);
                    break;
                }
                $chain[] = strtolower($root->name->toString());
                $forms[] = $this->callbackForm($root);
                $chainEnds[] = max(0, $root->getEndFilePos());
                $chainValid = $chainValid && $this->callbackShape($root);
                $root = $root->var;
            }
            $direct = $root instanceof Expr\MethodCall && $root->name instanceof Node\Identifier
                && in_array(strtolower($root->name->toString()), ['stream', 'queue', 'broadcast', 'broadcastnow', 'broadcastonqueue'], true);
            $sourceCall = ($root instanceof Expr\MethodCall || $root instanceof Expr\StaticCall || $root instanceof Expr\FuncCall) && ! $root->isFirstClassCallable();
            if ($direct || $root instanceof Expr\Variable || $sourceCall) {
                $arg = count($node->args) === 1 ? $node->args[0] : null;
                $callback = $arg !== null && ! $arg->unpack && ($arg->name === null || $arg->name->toString() === 'callback')
                    && ($arg->value instanceof Expr\Closure || $arg->value instanceof Expr\ArrowFunction)
                    ? ($this->owners[$arg->value->getStartFilePos()] ?? null) : null;
                $offset = max(0, $node->getStartFilePos());
                $kind = $direct ? 'ai-response-callback' : 'source-response-callback';
                $this->elements[] = new CatalogElement(CatalogElement::identity($file->path, $kind, $callbackMethod.':'.$node->getEndFilePos(), $offset), 'Source response '.$callbackMethod, $kind,
                    max(1, $node->getStartLine()), max(1, $node->getEndLine()), $offset, $owner,
                    metadata: ['method' => $callbackMethod, 'chain' => $chain, 'chain_valid' => $chainValid, 'invocation_offset' => $direct ? max(0, $root->getStartFilePos()) : null, 'target' => $callback?->kind === 'closure' ? $callback->id : null, 'end_offset' => max(0, $node->getEndFilePos()),
                        'argument_forms' => $forms, 'argument_offset' => $arg === null ? null : max(0, $arg->value->getStartFilePos()),
                        'named_hash' => $arg?->value instanceof Node\Scalar\String_ ? hash('sha256', strtolower(ltrim($arg->value->value, '\\'))) : null,
                        'named_method' => $arg === null ? null : $this->namedCallbackMethod($arg->value), 'chain_ends' => $chainEnds]);
            }
        }
        foreach ($node->getSubNodeNames() as $key) {
            foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) {
                if ($child instanceof Node) {
                    $this->visit($file, $child, $owner, $symbols, $conditional);
                }
            }
        }
    }

    private function callbackShape(Expr\MethodCall $call): bool
    {
        $arg = count($call->args) === 1 ? $call->args[0] : null;

        return ! $call->isFirstClassCallable() && $arg !== null && ! $arg->unpack
            && ($arg->name === null || $arg->name->toString() === 'callback')
            && ! $arg->value instanceof Node\Scalar\Int_ && ! $arg->value instanceof Node\Scalar\Float_
            && ! ($arg->value instanceof Expr\ConstFetch && in_array(strtolower($arg->value->name->toString()), ['null', 'true', 'false'], true));
    }

    private function callbackForm(Expr\MethodCall $call): string
    {
        $value = count($call->args) === 1 ? $call->args[0]->value : null;

        return match (true) {
            $value instanceof Expr\Closure, $value instanceof Expr\ArrowFunction => 'closure',
            ($value instanceof Expr\MethodCall || $value instanceof Expr\StaticCall || $value instanceof Expr\FuncCall) && $value->isFirstClassCallable() => 'first-class',
            $value instanceof Node\Scalar\String_ => 'named',
            $value instanceof Expr\Array_ => 'array',
            default => 'dynamic',
        };
    }

    /** @return array{receiver_hash: string, method_hash: string}|null */
    private function namedCallbackMethod(Expr $value): ?array
    {
        if ($value instanceof Node\Scalar\String_) {
            return $this->namedMethod($value->value);
        }
        if (! $value instanceof Expr\Array_ || count($value->items) !== 2) {
            return null;
        }
        $parts = [];
        $items = ImpactExtractor::callableArrayItems($value);
        if ($items === []) {
            return null;
        }
        ksort($items);
        foreach ($items as $position => $item) {
            if ($item === null || $item->unpack || $item->byRef || ! $item->value instanceof Node\Scalar\String_
                || $item->key !== null && (! $item->key instanceof Node\Scalar\Int_ || $item->key->value !== $position)) {
                return null;
            }
            $parts[] = $item->value->value;
        }

        return $this->namedMethod(implode('::', $parts));
    }

    /** @return array{receiver_hash: string, method_hash: string}|null */
    private function namedMethod(string $value): ?array
    {
        if (strlen($value) > 1000 || preg_match('/\A\\\\?[a-zA-Z_][a-zA-Z0-9_\\\\]*::[a-zA-Z_][a-zA-Z0-9_]*\z/D', $value) !== 1) {
            return null;
        }
        [$receiver, $method] = explode('::', $value, 2);

        return ['receiver_hash' => hash('sha256', strtolower(ltrim($receiver, '\\'))), 'method_hash' => hash('sha256', strtolower($method))];
    }
}
