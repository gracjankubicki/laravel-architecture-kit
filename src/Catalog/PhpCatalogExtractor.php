<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisLimitReason;
use GracjanKubicki\ArchitectureKit\Analysis\GraphBuildEligibility;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Declarations retain lexical ownership. Contains does not imply execution. */
final class PhpCatalogExtractor
{
    /** @var list<CatalogElement> */
    private array $elements = [];

    /** @var list<CatalogRelation> */
    private array $relations = [];

    /** @var list<CatalogDiagnostic> */
    private array $diagnostics = [];

    private int $visited = 0;

    private bool $limited = false;

    /** @var array<string, list<string>> */
    private array $propertyTypes = [];

    /** @var array<string, array{visibility: string, static: bool}> */
    private array $propertyFlags = [];

    public function extract(FileContext $file): CatalogFacts
    {
        $this->elements = $this->relations = $this->diagnostics = [];
        $this->visited = 0;
        $this->limited = false;
        $this->propertyTypes = [];
        $this->propertyFlags = [];
        $id = CatalogElement::identity($file->path, 'file', $file->path);
        $this->elements[] = new CatalogElement($id, $file->path, 'file', 1, substr_count($file->contents, "\n") + 1, 0);
        if (($reason = ImpactExtractor::sourceLimit(strlen($file->contents))) !== null) {
            $this->diagnostics[] = new CatalogDiagnostic('source_limit', $reason, 1, $id);
        } elseif (($nodes = $file->syntax()) === null) {
            $this->diagnostics[] = new CatalogDiagnostic('parse_error', $file->parseError() ?? 'Source could not be parsed.', 1, $id);
        } else {
            foreach ($nodes as $node) {
                $this->visit($file, $node, $id, '');
            }
        }

        return new CatalogFacts($file->path, $this->elements, $this->relations, $this->diagnostics);
    }

    /** @return array{complete: bool, offsets: list<int>, limit_reason: ?string} */
    private function returnSites(Node $callable): array
    {
        $offsets = Node::is($callable, 'expression.arrow-function') ? [max(0, $callable->getStartFilePos())] : [];
        $pending = Node::is($callable, 'expression.arrow-function') ? [$callable->expr] : ($callable->stmts ?? []);
        $complete = Node::is($callable, 'expression.arrow-function') || $callable->stmts !== null;
        $visited = 0;
        while ($pending !== []) {
            $node = array_pop($pending);
            if (++$visited > 10000) {
                GraphBuildEligibility::report(AnalysisLimitReason::Structure);

                return ['complete' => false, 'offsets' => $offsets, 'limit_reason' => 'structure'];
            }
            if ($visited % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
                return ['complete' => false, 'offsets' => $offsets, 'limit_reason' => 'memory'];
            }
            if (Node::is($node, 'statement.class-like') || Node::is($node, 'statement.function') || Node::is($node, 'expression.closure') || Node::is($node, 'expression.arrow-function')) {
                continue;
            }
            if (Node::is($node, 'expression.yield') || Node::is($node, 'expression.yield-from')) {
                $complete = false;
            }
            if (Node::is($node, 'statement.return')) {
                if (count($offsets) >= 128) {
                    GraphBuildEligibility::report(AnalysisLimitReason::Structure);

                    return ['complete' => false, 'offsets' => $offsets, 'limit_reason' => 'structure'];
                }
                $offsets[] = max(0, $node->getStartFilePos());
            }
            foreach ($node->getSubNodeNames() as $key) {
                $children = Node::is($node->$key, 'node') ? [$node->$key] : (is_array($node->$key) ? $node->$key : []);
                foreach ($children as $child) {
                    if (Node::is($child, 'node')) {
                        $pending[] = $child;
                    }
                }
            }
        }
        sort($offsets);

        return ['complete' => $complete, 'offsets' => $offsets, 'limit_reason' => null];
    }

    private function visit(FileContext $file, Node $node, string $owner, string $className): void
    {
        if ($this->limited) {
            return;
        }
        if (++$this->visited > 25000 || count($this->elements) >= 10000 || ($this->visited % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null)) {
            $this->limited = true;
            GraphBuildEligibility::report(ImpactExtractor::sourceLimitReason(0) ?? AnalysisLimitReason::Structure);
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'PHP catalog extraction reached its node or memory budget.', max(1, $node->getStartLine()), $owner);

            return;
        }
        $kind = $name = null;
        $metadata = [];
        if (Node::is($node, 'statement.class-like')) {
            $kind = match (true) {
                Node::is($node, 'statement.interface') => 'interface',
                Node::is($node, 'statement.trait') => 'trait',
                Node::is($node, 'statement.enum') => 'enum',
                default => 'class',
            };
            $name = isset($node->namespacedName) ? $node->namespacedName->toString() : '(anonymous) '.$file->path.':'.$node->getStartFilePos();
            $className = $name;
            $metadata['anonymous'] = $node->name === null;
            $metadata['abstract'] = Node::is($node, 'statement.class') && $node->isAbstract();
            $metadata['final'] = Node::is($node, 'statement.class') && $node->isFinal();
            $metadata['trait_adaptations'] = count(array_filter($node->getTraitUses(), fn ($use) => $use->adaptations !== [])) > 0;
            $metadata['trait_rules'] = [];
            foreach ($node->getTraitUses() as $use) {
                foreach ($use->adaptations as $adaptation) {
                    $rule = ['trait' => $adaptation->trait === null ? null : $file->resolvedName($adaptation->trait), 'method' => strtolower($adaptation->method->toString()),
                        'alias' => null, 'excluded' => [], 'visibility' => null, 'final' => false];
                    if (Node::is($adaptation, 'statement.trait-use-adaptation.precedence')) {
                        $rule['excluded'] = array_map(fn ($type) => $file->resolvedName($type), $adaptation->insteadof);
                    } elseif (Node::is($adaptation, 'statement.trait-use-adaptation.alias')) {
                        $rule['alias'] = $adaptation->newName === null ? null : strtolower($adaptation->newName->toString());
                        $rule['visibility'] = match (($adaptation->newModifier ?? 0) & 7) {
                            Node::MODIFIER_PRIVATE => 'private', Node::MODIFIER_PROTECTED => 'protected', Node::MODIFIER_PUBLIC => 'public', default => null,
                        };
                    }
                    if (Node::is($adaptation, 'statement.trait-use-adaptation.alias')) {
                        $rule['final'] = (($adaptation->newModifier ?? 0) & Node::MODIFIER_FINAL) !== 0;
                    }
                    $metadata['trait_rules'][] = $rule;
                }
            }
            foreach ($node->getProperties() as $property) {
                foreach ($property->props as $prop) {
                    $this->propertyTypes[$className.'::$'.$prop->name->toString()] = $this->resolvedTypes($file, $property->type, $className);
                    $this->propertyFlags[$className.'::$'.$prop->name->toString()] = ['visibility' => $property->isPrivate() ? 'private' : ($property->isProtected() ? 'protected' : 'public'), 'static' => $property->isStatic()];
                }
            }
        } elseif (Node::is($node, 'statement.class-method')) {
            $kind = 'method';
            $name = $className.'::'.$node->name->toString();
            $metadata = ['static' => $node->isStatic(), 'final' => $node->isFinal(), 'abstract' => $node->isAbstract(), 'visibility' => $node->isPrivate() ? 'private' : ($node->isProtected() ? 'protected' : 'public')];
            $metadata['return_types'] = $this->returnTypes($file, $node, $className);
        } elseif (Node::is($node, 'statement.function')) {
            $kind = 'function';
            $name = isset($node->namespacedName) ? $node->namespacedName->toString() : $node->name->toString();
            $metadata['return_types'] = $this->returnTypes($file, $node, '');
        } elseif (Node::is($node, 'expression.closure') || Node::is($node, 'expression.arrow-function')) {
            $kind = 'closure';
            $name = '(closure) '.$file->path.':'.$node->getStartFilePos();
            $metadata['arrow'] = Node::is($node, 'expression.arrow-function');
            $metadata['return_types'] = $this->returnTypes($file, $node, $className);
        } elseif (Node::is($node, 'property-item')) {
            $kind = 'property';
            $name = $className.'::$'.$node->name->toString();
            $metadata['types'] = $this->propertyTypes[$name] ?? [];
            $metadata = [...$metadata, ...($this->propertyFlags[$name] ?? [])];
            if ($node->name->toString() === 'collects') {
                $metadata['resource_collects'] = $this->resourceCollects($file, $node->default, $className);
            }
            if ($node->name->toString() === 'connector') {
                $type = Node::is($node->default, 'expression.class-const-fetch') && Node::is($node->default->class, 'name')
                    && Node::is($node->default->name, 'identifier') && strtolower($node->default->name->toString()) === 'class'
                    && ! in_array(strtolower($node->default->class->toString()), ['self', 'static', 'parent'], true)
                    ? $file->resolvedName($node->default->class) : null;
                if ($type !== null && (strlen($type) > 500 || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\z/D', $type) !== 1)) {
                    $type = null;
                }
                $metadata['saloon_connector'] = ['resolved' => $type !== null, 'type' => $type];
            }
            if ($node->name->toString() === 'allowBaseUrlOverride') {
                $constant = Node::is($node->default, 'expression.const-fetch') ? strtolower($node->default->name->toString()) : null;
                $metadata['saloon_url_override'] = ['resolved' => in_array($constant, ['true', 'false', 'null'], true),
                    'value' => $constant === 'true' ? true : ($constant === 'false' ? false : null)];
            }
        } elseif (Node::is($node, 'param') && $node->flags !== 0 && Node::is($node->var, 'expression.variable') && is_string($node->var->name)) {
            $kind = 'property';
            $name = $className.'::$'.$node->var->name;
            $metadata['promoted'] = true;
            $metadata['visibility'] = $node->isPrivate() ? 'private' : ($node->isProtected() ? 'protected' : 'public');
            $metadata['static'] = false;
            $metadata['types'] = $this->resolvedTypes($file, $node->type, $className);
        } elseif (Node::is($node, 'attribute')) {
            $kind = 'attribute';
            $name = $file->resolvedName($node->name);
            // Only framework middleware selectors are retained, never generic attribute payloads.
            if ($name === 'Illuminate\\Routing\\Attributes\\Controllers\\Middleware') {
                $metadata['controller_middleware'] = $this->middlewareAttribute($file, $node, $className);
            }
            if ($name === 'Illuminate\\Http\\Resources\\Attributes\\Collects') {
                $argument = null;
                $shape = count($node->args) === 1;
                foreach ($node->args as $position => $arg) {
                    $shape = $shape && ! $arg->unpack && ($arg->name === null && $position === 0 || $arg->name?->toString() === 'class');
                    if ($arg->name?->toString() === 'class' || $arg->name === null && $position === 0) {
                        $argument = $arg->value;
                    }
                }
                $metadata['resource_collects'] = $shape && $argument !== null ? $this->resourceCollects($file, $argument, $className) : ['resolved' => false, 'target' => null];
                if ($metadata['resource_collects']['target'] === null) {
                    $metadata['resource_collects']['resolved'] = false;
                }
            }
        } elseif (Node::is($node, 'statement.enum-case')) {
            $kind = 'enum-case';
            $name = $className.'::'.$node->name->toString();
        }
        if ($kind !== null && $name !== null) {
            if (Node::is($node, 'statement.class-method') || Node::is($node, 'statement.function') || Node::is($node, 'expression.closure') || Node::is($node, 'expression.arrow-function')) {
                $metadata['call_parameters'] = ['complete' => count($node->params) <= 128, 'parameters' => []];
                $metadata['return_sites'] = $this->returnSites($node);
                if ($metadata['return_sites']['limit_reason'] !== null) {
                    $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Callable return summary reached its node, return count or memory budget.', max(1, $node->getStartLine()),
                        limitReason: $metadata['return_sites']['limit_reason']);
                }
                $lastRequired = -1;
                foreach ($node->params as $position => $param) {
                    if ($param->default === null && ! $param->variadic) {
                        $lastRequired = $position;
                    }
                }
                foreach (array_slice($node->params, 0, 128) as $position => $param) {
                    if (! is_string($param->var->name) || strlen($param->var->name) > 1000 || in_array($param->var->name, array_column($metadata['call_parameters']['parameters'], 'name'), true)) {
                        $metadata['call_parameters']['complete'] = false;

                        continue;
                    }
                    if ($param->variadic && ($position !== count($node->params) - 1 || $param->default !== null)) {
                        $metadata['call_parameters']['complete'] = false;
                    }
                    $metadata['call_parameters']['parameters'][] = ['name' => $param->var->name, 'required' => ! $param->variadic && $position <= $lastRequired,
                        'variadic' => $param->variadic, 'by_ref' => $param->byRef];
                }
                $metadata['parameters'] = [];
                foreach ($node->params as $param) {
                    $metadata['parameters'][] = ['types' => $this->resolvedTypes($file, $param->type, $className), 'line' => max(1, $param->getStartLine())];
                }
                $returned = Node::is($node, 'expression.arrow-function') ? $node->expr
                    : (count($node->stmts ?? []) === 1 && Node::is($node->stmts[0], 'statement.return') ? $node->stmts[0]->expr : null);
                if (Node::is($returned, 'expression.static-call') && Node::is($returned->class, 'name') && Node::is($returned->name, 'identifier') && ! $returned->isFirstClassCallable()) {
                    $metadata['static_return'] = ['class' => $this->resolvedTypes($file, $returned->class, $className)[0], 'method' => $returned->name->toString()];
                }
            }
            $metadata['end_offset'] = max(0, $node->getEndFilePos());
            $id = CatalogElement::identity($file->path, $kind, $name, max(0, $node->getStartFilePos()));
            $this->elements[] = new CatalogElement($id, $name, $kind, max(1, $node->getStartLine()), max(1, $node->getEndLine()), max(0, $node->getStartFilePos()), $owner, metadata: $metadata);
            $this->relations[] = new CatalogRelation($owner, $id, 'contains', max(1, $node->getStartLine()), max(1, $node->getEndLine()));
            $owner = $id;
            if ($kind === 'attribute') {
                $this->relations[] = new CatalogRelation($owner, 'php:'.$name, 'attribute-type', max(1, $node->getStartLine()), max(1, $node->getEndLine()));
            }
        }
        if (Node::is($node, 'statement.class-like')) {
            if (Node::is($node, 'statement.class') && $node->extends !== null) {
                $this->reference($file, $owner, $node->extends, 'extends');
            }
            foreach (Node::is($node, 'statement.interface') ? $node->extends : (Node::is($node, 'statement.class') || Node::is($node, 'statement.enum') ? $node->implements : []) as $type) {
                $this->reference($file, $owner, $type, Node::is($node, 'statement.interface') ? 'extends' : 'implements');
            }
        }
        if (Node::is($node, 'statement.trait-use')) {
            foreach ($node->traits as $type) {
                $this->reference($file, $owner, $type, 'uses-trait');
            }
        }
        if (Node::is($node, 'param') || Node::is($node, 'statement.property')) {
            $this->typeReferences($file, $owner, $node->type);
        }
        if (Node::is($node, 'statement.class-method') || Node::is($node, 'statement.function') || Node::is($node, 'expression.closure') || Node::is($node, 'expression.arrow-function')) {
            $this->typeReferences($file, $owner, $node->returnType);
        }
        if (Node::is($node, 'expression.include')) {
            $path = $this->includePath($node->expr, $file);
            if ($path === null) {
                $this->diagnostics[] = new CatalogDiagnostic('dynamic_include', 'Included file is dynamic or cannot be located safely from source.', max(1, $node->getStartLine()), $owner);
            } else {
                if ($this->anchoredPath($node->expr)) {
                    $this->relations[] = new CatalogRelation($owner, 'file:'.$path, 'includes-file', max(1, $node->getStartLine()), max(1, $node->getEndLine()));
                } else {
                    $callerPath = $this->normalizePath(dirname($file->path).'/'.$this->pathLiteral($node->expr, $file));
                    $candidates = array_values(array_unique(array_filter([$path, $callerPath], 'is_string')));
                    $this->relations[] = new CatalogRelation($owner, 'include:'.hash('xxh128', serialize($candidates)), 'includes-file', max(1, $node->getStartLine()), max(1, $node->getEndLine()), 'conditional', ['candidate_paths' => $candidates]);
                    $this->diagnostics[] = new CatalogDiagnostic('include_search_path', 'Relative include candidates depend on the runtime working directory and include_path; source candidates are not a proven selection.', max(1, $node->getStartLine()), $owner);
                }
            }
        }
        if (Node::is($node, 'expression.method-call') || Node::is($node, 'expression.nullsafe-method-call') || Node::is($node, 'expression.static-call')) {
            if (Node::is($node->name, 'expression')) {
                $this->diagnostics[] = new CatalogDiagnostic('dynamic_call', 'Dynamic call target needs source inspection.', max(1, $node->getStartLine()), $owner);
            }
        }
        foreach ($node->getSubNodeNames() as $key) {
            $value = $node->$key;
            foreach (is_array($value) ? $value : [$value] as $child) {
                if (Node::is($child, 'node')) {
                    $this->visit($file, $child, $owner, $className);
                }
            }
        }
    }

    /** @return array{resolved: bool, target: ?string} */
    private function resourceCollects(FileContext $file, ?Node $value, string $className): array
    {
        if (Node::is($value, 'expression.class-const-fetch') && Node::is($value->class, 'name') && Node::is($value->name, 'identifier') && strtolower($value->name->toString()) === 'class') {
            return ['resolved' => true, 'target' => $this->resolvedTypes($file, $value->class, $className)[0]];
        }
        if (Node::is($value, 'scalar.string') && preg_match('/^[\\\\a-zA-Z_][\\\\a-zA-Z0-9_]*$/D', $value->value) === 1) {
            return ['resolved' => true, 'target' => ltrim($value->value, '\\')];
        }
        if ($value === null || Node::is($value, 'expression.const-fetch') && in_array(strtolower($value->name->toString()), ['null', 'false'], true)
            || Node::is($value, 'scalar.string') && in_array($value->value, ['', '0'], true)
            || Node::is($value, 'scalar.int') && $value->value === 0 || Node::is($value, 'scalar.float') && $value->value === 0.0
            || Node::is($value, 'expression.array') && $value->items === []) {
            return ['resolved' => true, 'target' => null];
        }

        return ['resolved' => false, 'target' => null];
    }

    /** @return array<string, mixed> */
    private function middlewareAttribute(FileContext $file, Node $attribute, string $className): array
    {
        $args = [];
        $shape = true;
        foreach ($attribute->args as $position => $arg) {
            $key = $arg->name?->toString() ?? (['middleware', 'only', 'except'][$position] ?? 'unknown');
            if ($arg->unpack || ! in_array($key, ['middleware', 'only', 'except'], true) || isset($args[$key])) {
                $shape = false;
            }
            $args[$key] = $arg->value;
        }
        $middleware = $args['middleware'] ?? null;
        $name = Node::is($middleware, 'scalar.string') ? $middleware->value : null;
        if (Node::is($middleware, 'expression.class-const-fetch') && Node::is($middleware->class, 'name') && Node::is($middleware->name, 'identifier') && strtolower($middleware->name->toString()) === 'class') {
            $name = $this->resolvedTypes($file, $middleware->class, $className)[0] ?? null;
        }
        $filters = [];
        $resolved = true;
        foreach (['only', 'except'] as $key) {
            $value = $args[$key] ?? null;
            $filters[$key] = [];
            if ($value === null || Node::is($value, 'expression.const-fetch') && strtolower($value->name->toString()) === 'null') {
                continue;
            }
            if (Node::is($value, 'expression.array') && count($value->items) > 1000) {
                $this->limited = true;
                GraphBuildEligibility::report(ImpactExtractor::sourceLimitReason(0) ?? AnalysisLimitReason::Structure);
                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Controller middleware attribute filters exceeded their 1000 item budget.', max(1, $attribute->getStartLine()));
                $resolved = false;

                continue;
            }
            if (! Node::is($value, 'expression.array')) {
                $resolved = false;

                continue;
            }
            foreach ($value->items as $item) {
                if ($item === null || $item->unpack || $item->key !== null || ! Node::is($item->value, 'scalar.string')) {
                    $resolved = false;
                } else {
                    $filters[$key][] = $item->value->value;
                }
            }
        }

        return ['middleware' => $name, 'only' => $filters['only'], 'except' => $filters['except'], 'filters_resolved' => $resolved, 'shape_resolved' => $shape];
    }

    private function reference(FileContext $file, string $owner, Node $type, string $kind): void
    {
        $this->relations[] = new CatalogRelation($owner, 'php:'.$file->resolvedName($type), $kind, max(1, $type->getStartLine()), max(1, $type->getEndLine()));
    }

    private function typeReferences(FileContext $file, string $owner, ?Node $type): void
    {
        if (Node::is($type, 'name')) {
            $this->reference($file, $owner, $type, 'type-reference');
        } elseif (Node::is($type, 'nullable-type')) {
            $this->typeReferences($file, $owner, $type->type);
        } elseif (Node::is($type, 'union-type') || Node::is($type, 'intersection-type')) {
            foreach ($type->types as $member) {
                $this->typeReferences($file, $owner, $member);
            }
        }
    }

    /** @return list<string> */
    private function resolvedTypes(FileContext $file, ?Node $type, string $className): array
    {
        if (Node::is($type, 'name')) {
            return [match (strtolower($type->toString())) {
                'self', 'static' => $className,
                'parent' => '@parent:'.$className,
                default => $file->resolvedName($type),
            }];
        }
        if (Node::is($type, 'nullable-type')) {
            return $this->resolvedTypes($file, $type->type, $className);
        }
        if (Node::is($type, 'union-type') || Node::is($type, 'intersection-type')) {
            $types = [];
            foreach ($type->types as $member) {
                array_push($types, ...$this->resolvedTypes($file, $member, $className));
            }

            return array_values(array_unique($types));
        }

        return [];
    }

    private function includePath(Node $node, FileContext $file): ?string
    {
        $literal = $this->pathLiteral($node, $file);
        if ($literal === null || str_starts_with($literal, '/') || str_contains($literal, "\0")) {
            return null;
        }

        return $this->normalizePath($literal) ?? (! $this->anchoredPath($node) ? $this->normalizePath(dirname($file->path).'/'.$literal) : null);
    }

    private function normalizePath(string $literal): ?string
    {
        $parts = [];
        foreach (explode('/', $literal) as $part) {
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return $parts === [] ? null : implode('/', $parts);
    }

    private function anchoredPath(Node $node, int $depth = 0): bool
    {
        if ($depth >= 16) {
            return false;
        }

        return Node::is($node, 'scalar.magic-const.dir') || Node::is($node, 'scalar.magic-const.file')
            || Node::is($node, 'expression.binary-op.concat') && $this->anchoredPath($node->left, $depth + 1);
    }

    /** @return list<string> */
    private function returnTypes(FileContext $file, Node $callable, string $className): array
    {
        $declared = $this->resolvedTypes($file, $callable->returnType, $className);
        if ($declared !== []) {
            return $declared;
        }
        $expression = Node::is($callable, 'expression.arrow-function') ? $callable->expr
            : (count($callable->stmts ?? []) === 1 && Node::is($callable->stmts[0], 'statement.return') ? $callable->stmts[0]->expr : null);
        if (Node::is($expression, 'expression.new')) {
            if (Node::is($expression->class, 'name')) {
                return $this->resolvedTypes($file, $expression->class, $className);
            }
            if (Node::is($expression->class, 'statement.class')) {
                return ['(anonymous) '.$file->path.':'.$expression->class->getStartFilePos()];
            }
        }
        if (Node::is($expression, 'expression.closure') || Node::is($expression, 'expression.arrow-function')) {
            return ['@closure:(closure) '.$file->path.':'.$expression->getStartFilePos()];
        }
        if (Node::is($expression, 'expression.variable') && is_string($expression->name)) {
            if ($expression->name === 'this' && $className !== '') {
                return [$className];
            }
            foreach ($callable->params as $param) {
                if (Node::is($param->var, 'expression.variable') && $param->var->name === $expression->name) {
                    return $this->resolvedTypes($file, $param->type, $className);
                }
            }
        }

        return [];
    }

    private function pathLiteral(Node $node, FileContext $file, int $depth = 0): ?string
    {
        if ($depth >= 16) {
            return null;
        }
        if (Node::is($node, 'scalar.string')) {
            return strlen($node->value) <= 500 ? $node->value : null;
        }
        if (Node::is($node, 'scalar.magic-const.dir')) {
            return dirname($file->path);
        }
        if (Node::is($node, 'scalar.magic-const.file')) {
            return $file->path;
        }
        if (Node::is($node, 'expression.binary-op.concat')) {
            $left = $this->pathLiteral($node->left, $file, $depth + 1);
            $right = $this->pathLiteral($node->right, $file, $depth + 1);

            return $left !== null && $right !== null ? $left.$right : null;
        }

        return null;
    }
}
