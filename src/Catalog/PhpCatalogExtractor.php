<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

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
        } elseif (($nodes = $file->ast()) === null) {
            $this->diagnostics[] = new CatalogDiagnostic('parse_error', $file->parseError() ?? 'Source could not be parsed.', 1, $id);
        } else {
            foreach ($nodes as $node) {
                $this->visit($file, $node, $id, '');
            }
        }

        return new CatalogFacts($file->path, $this->elements, $this->relations, $this->diagnostics);
    }

    /** @return array{complete: bool, offsets: list<int>, limit_reason: ?string} */
    private function returnSites(Stmt\ClassMethod|Stmt\Function_|Expr\Closure|Expr\ArrowFunction $callable): array
    {
        $offsets = $callable instanceof Expr\ArrowFunction ? [max(0, $callable->getStartFilePos())] : [];
        $pending = $callable instanceof Expr\ArrowFunction ? [$callable->expr] : ($callable->stmts ?? []);
        $complete = $callable instanceof Expr\ArrowFunction || $callable->stmts !== null;
        $visited = 0;
        while ($pending !== []) {
            $node = array_pop($pending);
            if (++$visited > 10000) {
                return ['complete' => false, 'offsets' => $offsets, 'limit_reason' => 'structure'];
            }
            if ($visited % 128 === 0 && ImpactExtractor::sourceLimit(0) !== null) {
                return ['complete' => false, 'offsets' => $offsets, 'limit_reason' => 'memory'];
            }
            if ($node instanceof Stmt\ClassLike || $node instanceof Stmt\Function_ || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
                continue;
            }
            if ($node instanceof Expr\Yield_ || $node instanceof Expr\YieldFrom) {
                $complete = false;
            }
            if ($node instanceof Stmt\Return_) {
                if (count($offsets) >= 128) {
                    return ['complete' => false, 'offsets' => $offsets, 'limit_reason' => 'structure'];
                }
                $offsets[] = max(0, $node->getStartFilePos());
            }
            foreach ($node->getSubNodeNames() as $key) {
                $children = $node->$key instanceof Node ? [$node->$key] : (is_array($node->$key) ? $node->$key : []);
                foreach ($children as $child) {
                    if ($child instanceof Node) {
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
            $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'PHP catalog extraction reached its node or memory budget.', max(1, $node->getStartLine()), $owner);

            return;
        }
        $kind = $name = null;
        $metadata = [];
        if ($node instanceof Stmt\ClassLike) {
            $kind = match (true) {
                $node instanceof Stmt\Interface_ => 'interface',
                $node instanceof Stmt\Trait_ => 'trait',
                $node instanceof Stmt\Enum_ => 'enum',
                default => 'class',
            };
            $name = isset($node->namespacedName) ? $node->namespacedName->toString() : '(anonymous) '.$file->path.':'.$node->getStartFilePos();
            $className = $name;
            $metadata['anonymous'] = $node->name === null;
            $metadata['abstract'] = $node instanceof Stmt\Class_ && $node->isAbstract();
            $metadata['final'] = $node instanceof Stmt\Class_ && $node->isFinal();
            $metadata['trait_adaptations'] = count(array_filter($node->getTraitUses(), fn ($use) => $use->adaptations !== [])) > 0;
            $metadata['trait_rules'] = [];
            foreach ($node->getTraitUses() as $use) {
                foreach ($use->adaptations as $adaptation) {
                    $rule = ['trait' => $adaptation->trait === null ? null : $file->resolvedName($adaptation->trait), 'method' => strtolower($adaptation->method->toString()),
                        'alias' => null, 'excluded' => [], 'visibility' => null, 'final' => false];
                    if ($adaptation instanceof Stmt\TraitUseAdaptation\Precedence) {
                        $rule['excluded'] = array_map(fn ($type) => $file->resolvedName($type), $adaptation->insteadof);
                    } elseif ($adaptation instanceof Stmt\TraitUseAdaptation\Alias) {
                        $rule['alias'] = $adaptation->newName === null ? null : strtolower($adaptation->newName->toString());
                        $rule['visibility'] = match (($adaptation->newModifier ?? 0) & 7) {
                            Stmt\Class_::MODIFIER_PRIVATE => 'private', Stmt\Class_::MODIFIER_PROTECTED => 'protected', Stmt\Class_::MODIFIER_PUBLIC => 'public', default => null,
                        };
                    }
                    if ($adaptation instanceof Stmt\TraitUseAdaptation\Alias) {
                        $rule['final'] = (($adaptation->newModifier ?? 0) & Stmt\Class_::MODIFIER_FINAL) !== 0;
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
        } elseif ($node instanceof Stmt\ClassMethod) {
            $kind = 'method';
            $name = $className.'::'.$node->name->toString();
            $metadata = ['static' => $node->isStatic(), 'final' => $node->isFinal(), 'abstract' => $node->isAbstract(), 'visibility' => $node->isPrivate() ? 'private' : ($node->isProtected() ? 'protected' : 'public')];
            $metadata['return_types'] = $this->returnTypes($file, $node, $className);
        } elseif ($node instanceof Stmt\Function_) {
            $kind = 'function';
            $name = isset($node->namespacedName) ? $node->namespacedName->toString() : $node->name->toString();
            $metadata['return_types'] = $this->returnTypes($file, $node, '');
        } elseif ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $kind = 'closure';
            $name = '(closure) '.$file->path.':'.$node->getStartFilePos();
            $metadata['arrow'] = $node instanceof Expr\ArrowFunction;
            $metadata['return_types'] = $this->returnTypes($file, $node, $className);
        } elseif ($node instanceof Node\PropertyItem) {
            $kind = 'property';
            $name = $className.'::$'.$node->name->toString();
            $metadata['types'] = $this->propertyTypes[$name] ?? [];
            $metadata = [...$metadata, ...($this->propertyFlags[$name] ?? [])];
            if ($node->name->toString() === 'collects') {
                $metadata['resource_collects'] = $this->resourceCollects($file, $node->default, $className);
            }
            if ($node->name->toString() === 'connector') {
                $type = $node->default instanceof Expr\ClassConstFetch && $node->default->class instanceof Node\Name
                    && $node->default->name instanceof Node\Identifier && strtolower($node->default->name->toString()) === 'class'
                    && ! in_array(strtolower($node->default->class->toString()), ['self', 'static', 'parent'], true)
                    ? $file->resolvedName($node->default->class) : null;
                if ($type !== null && (strlen($type) > 500 || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*\z/D', $type) !== 1)) {
                    $type = null;
                }
                $metadata['saloon_connector'] = ['resolved' => $type !== null, 'type' => $type];
            }
            if ($node->name->toString() === 'allowBaseUrlOverride') {
                $constant = $node->default instanceof Expr\ConstFetch ? strtolower($node->default->name->toString()) : null;
                $metadata['saloon_url_override'] = ['resolved' => in_array($constant, ['true', 'false', 'null'], true),
                    'value' => $constant === 'true' ? true : ($constant === 'false' ? false : null)];
            }
        } elseif ($node instanceof Node\Param && $node->flags !== 0 && $node->var instanceof Expr\Variable && is_string($node->var->name)) {
            $kind = 'property';
            $name = $className.'::$'.$node->var->name;
            $metadata['promoted'] = true;
            $metadata['visibility'] = $node->isPrivate() ? 'private' : ($node->isProtected() ? 'protected' : 'public');
            $metadata['static'] = false;
            $metadata['types'] = $this->resolvedTypes($file, $node->type, $className);
        } elseif ($node instanceof Node\Attribute) {
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
        } elseif ($node instanceof Stmt\EnumCase) {
            $kind = 'enum-case';
            $name = $className.'::'.$node->name->toString();
        }
        if ($kind !== null && $name !== null) {
            if ($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_ || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
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
                $returned = $node instanceof Expr\ArrowFunction ? $node->expr
                    : (count($node->stmts ?? []) === 1 && $node->stmts[0] instanceof Stmt\Return_ ? $node->stmts[0]->expr : null);
                if ($returned instanceof Expr\StaticCall && $returned->class instanceof Node\Name && $returned->name instanceof Node\Identifier && ! $returned->isFirstClassCallable()) {
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
        if ($node instanceof Stmt\ClassLike) {
            if ($node instanceof Stmt\Class_ && $node->extends !== null) {
                $this->reference($file, $owner, $node->extends, 'extends');
            }
            foreach ($node instanceof Stmt\Interface_ ? $node->extends : ($node instanceof Stmt\Class_ || $node instanceof Stmt\Enum_ ? $node->implements : []) as $type) {
                $this->reference($file, $owner, $type, $node instanceof Stmt\Interface_ ? 'extends' : 'implements');
            }
        }
        if ($node instanceof Stmt\TraitUse) {
            foreach ($node->traits as $type) {
                $this->reference($file, $owner, $type, 'uses-trait');
            }
        }
        if ($node instanceof Node\Param || $node instanceof Stmt\Property) {
            $this->typeReferences($file, $owner, $node->type);
        }
        if ($node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_ || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $this->typeReferences($file, $owner, $node->returnType);
        }
        if ($node instanceof Expr\Include_) {
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
        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall || $node instanceof Expr\StaticCall) {
            if ($node->name instanceof Expr) {
                $this->diagnostics[] = new CatalogDiagnostic('dynamic_call', 'Dynamic call target needs source inspection.', max(1, $node->getStartLine()), $owner);
            }
        }
        foreach ($node->getSubNodeNames() as $key) {
            $value = $node->$key;
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node) {
                    $this->visit($file, $child, $owner, $className);
                }
            }
        }
    }

    /** @return array{resolved: bool, target: ?string} */
    private function resourceCollects(FileContext $file, ?Expr $value, string $className): array
    {
        if ($value instanceof Expr\ClassConstFetch && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier && strtolower($value->name->toString()) === 'class') {
            return ['resolved' => true, 'target' => $this->resolvedTypes($file, $value->class, $className)[0]];
        }
        if ($value instanceof Node\Scalar\String_ && preg_match('/^[\\\\a-zA-Z_][\\\\a-zA-Z0-9_]*$/D', $value->value) === 1) {
            return ['resolved' => true, 'target' => ltrim($value->value, '\\')];
        }
        if ($value === null || $value instanceof Expr\ConstFetch && in_array(strtolower($value->name->toString()), ['null', 'false'], true)
            || $value instanceof Node\Scalar\String_ && in_array($value->value, ['', '0'], true)
            || $value instanceof Node\Scalar\Int_ && $value->value === 0 || $value instanceof Node\Scalar\Float_ && $value->value === 0.0
            || $value instanceof Expr\Array_ && $value->items === []) {
            return ['resolved' => true, 'target' => null];
        }

        return ['resolved' => false, 'target' => null];
    }

    /** @return array<string, mixed> */
    private function middlewareAttribute(FileContext $file, Node\Attribute $attribute, string $className): array
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
        $name = $middleware instanceof Node\Scalar\String_ ? $middleware->value : null;
        if ($middleware instanceof Expr\ClassConstFetch && $middleware->class instanceof Node\Name && $middleware->name instanceof Node\Identifier && strtolower($middleware->name->toString()) === 'class') {
            $name = $this->resolvedTypes($file, $middleware->class, $className)[0] ?? null;
        }
        $filters = [];
        $resolved = true;
        foreach (['only', 'except'] as $key) {
            $value = $args[$key] ?? null;
            $filters[$key] = [];
            if ($value === null || $value instanceof Expr\ConstFetch && strtolower($value->name->toString()) === 'null') {
                continue;
            }
            if ($value instanceof Expr\Array_ && count($value->items) > 1000) {
                $this->limited = true;
                $this->diagnostics[] = new CatalogDiagnostic('catalog_limit', 'Controller middleware attribute filters exceeded their 1000 item budget.', max(1, $attribute->getStartLine()));
                $resolved = false;

                continue;
            }
            if (! $value instanceof Expr\Array_) {
                $resolved = false;

                continue;
            }
            foreach ($value->items as $item) {
                if ($item === null || $item->unpack || $item->key !== null || ! $item->value instanceof Node\Scalar\String_) {
                    $resolved = false;
                } else {
                    $filters[$key][] = $item->value->value;
                }
            }
        }

        return ['middleware' => $name, 'only' => $filters['only'], 'except' => $filters['except'], 'filters_resolved' => $resolved, 'shape_resolved' => $shape];
    }

    private function reference(FileContext $file, string $owner, Node\Name $type, string $kind): void
    {
        $this->relations[] = new CatalogRelation($owner, 'php:'.$file->resolvedName($type), $kind, max(1, $type->getStartLine()), max(1, $type->getEndLine()));
    }

    private function typeReferences(FileContext $file, string $owner, ?Node $type): void
    {
        if ($type instanceof Node\Name) {
            $this->reference($file, $owner, $type, 'type-reference');
        } elseif ($type instanceof Node\NullableType) {
            $this->typeReferences($file, $owner, $type->type);
        } elseif ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $member) {
                $this->typeReferences($file, $owner, $member);
            }
        }
    }

    /** @return list<string> */
    private function resolvedTypes(FileContext $file, ?Node $type, string $className): array
    {
        if ($type instanceof Node\Name) {
            return [match (strtolower($type->toString())) {
                'self', 'static' => $className,
                'parent' => '@parent:'.$className,
                default => $file->resolvedName($type),
            }];
        }
        if ($type instanceof Node\NullableType) {
            return $this->resolvedTypes($file, $type->type, $className);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
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

        return $node instanceof Node\Scalar\MagicConst\Dir || $node instanceof Node\Scalar\MagicConst\File
            || $node instanceof Expr\BinaryOp\Concat && $this->anchoredPath($node->left, $depth + 1);
    }

    /** @return list<string> */
    private function returnTypes(FileContext $file, Stmt\ClassMethod|Stmt\Function_|Expr\Closure|Expr\ArrowFunction $callable, string $className): array
    {
        $declared = $this->resolvedTypes($file, $callable->returnType, $className);
        if ($declared !== []) {
            return $declared;
        }
        $expression = $callable instanceof Expr\ArrowFunction ? $callable->expr
            : (count($callable->stmts ?? []) === 1 && $callable->stmts[0] instanceof Stmt\Return_ ? $callable->stmts[0]->expr : null);
        if ($expression instanceof Expr\New_) {
            if ($expression->class instanceof Node\Name) {
                return $this->resolvedTypes($file, $expression->class, $className);
            }
            if ($expression->class instanceof Stmt\Class_) {
                return ['(anonymous) '.$file->path.':'.$expression->class->getStartFilePos()];
            }
        }
        if ($expression instanceof Expr\Closure || $expression instanceof Expr\ArrowFunction) {
            return ['@closure:(closure) '.$file->path.':'.$expression->getStartFilePos()];
        }
        if ($expression instanceof Expr\Variable && is_string($expression->name)) {
            if ($expression->name === 'this' && $className !== '') {
                return [$className];
            }
            foreach ($callable->params as $param) {
                if ($param->var instanceof Expr\Variable && $param->var->name === $expression->name) {
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
        if ($node instanceof Node\Scalar\String_) {
            return strlen($node->value) <= 500 ? $node->value : null;
        }
        if ($node instanceof Node\Scalar\MagicConst\Dir) {
            return dirname($file->path);
        }
        if ($node instanceof Node\Scalar\MagicConst\File) {
            return $file->path;
        }
        if ($node instanceof Expr\BinaryOp\Concat) {
            $left = $this->pathLiteral($node->left, $file, $depth + 1);
            $right = $this->pathLiteral($node->right, $file, $depth + 1);

            return $left !== null && $right !== null ? $left.$right : null;
        }

        return null;
    }
}
