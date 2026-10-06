<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\PublicApi;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Revision\SourceSnapshot;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use Illuminate\Console\Parser;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;

/** Laravel declarations and MCP schemas are read, never registered or invoked. */
final class FrameworkContracts
{
    /** @var array<string, array<string, mixed>> */
    public array $entries = [];

    /** @var list<array{code: string, path: string, message: string}> */
    public array $notices = [];

    private Standard $printer;

    private EffectiveApi $exposure;

    public function __construct(private readonly SourceSnapshot $snapshot, AutoloadSurface $surface, private readonly PhpContracts $php)
    {
        $this->printer = new Standard;
        $this->exposure = new EffectiveApi($php);
        $initialExposureNotices = count($this->exposure->notices);
        $finder = new NodeFinder;
        foreach (array_keys($surface->files) as $path) {
            $ceiling = MemoryLimit::bytes();
            if ($ceiling !== null && memory_get_usage(true) + strlen($snapshot->files[$path]) * 40 > $ceiling * 0.75) {
                $this->notices[] = ['code' => 'E_FRAMEWORK_CONTRACT_UNRESOLVED', 'path' => $path, 'message' => 'Framework parsing skipped because of the process memory budget.'];

                continue;
            }
            $file = new FileContext($path, $snapshot->files[$path]);
            $nodes = $file->ast();
            if ($nodes === null) {
                continue;
            }
            $this->markRegistrationContext($nodes);
            foreach ($finder->findInstanceOf($nodes, Stmt\ClassLike::class) as $class) {
                if ($class->name === null) {
                    continue;
                }
                $name = isset($class->namespacedName) ? $class->namespacedName->toString() : $class->name->toString();
                if (! isset($php->classes[strtolower($name)])) {
                    continue;
                }
                if ($this->inherits($name, 'Illuminate\\Console\\Command') && ! $php->classes[strtolower($name)]['abstract']) {
                    $signature = $this->inheritedProperty($class, $name, 'signature');
                    if ($signature !== null) {
                        $value = StaticValue::read($signature);
                        if ($value['known'] && is_string($value['value'])) {
                            $this->command($value['value'], $file, $signature, $name);
                        } else {
                            $this->notice($file, 'Dynamic command signature: '.$name);
                        }
                    } else {
                        $this->notice($file, 'Command uses inherited or programmatic arguments/options: '.$name);
                    }
                }
                if ($this->inherits($name, 'Laravel\\Mcp\\Server\\Tool') && ! $php->classes[strtolower($name)]['abstract']) {
                    $this->tool($class, $name, $file);
                }
                foreach ($php->classes[strtolower($name)]['traits'] as $trait) {
                    if (strtolower($trait) === 'illuminate\\foundation\\events\\dispatchable') {
                        $this->event($name, $file, $class);
                    }
                }
            }
            foreach ($finder->findInstanceOf($nodes, Node\Expr\MethodCall::class) as $call) {
                if ($call->name instanceof Node\Identifier && in_array($call->name->toString(), ['publishes', 'publishesMigrations'], true) && $call->var instanceof Node\Expr\Variable && $call->var->name === 'this') {
                    $this->publish($call, $file);
                }
            }
            foreach ($finder->findInstanceOf($nodes, Node\Expr\StaticCall::class) as $call) {
                if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
                    continue;
                }
                $class = strtolower($file->resolvedName($call->class));
                $method = strtolower($call->name->toString());
                $args = $call->getArgs();
                if ($class === 'illuminate\\support\\facades\\artisan' && $method === 'command' && isset($args[0])) {
                    $signature = StaticValue::read($args[0]->value);
                    if ($signature['known'] && is_string($signature['value'])) {
                        $this->command($signature['value'], $file, $call, 'callback');
                    } else {
                        $this->notice($file, 'Dynamic Artisan callback signature.');
                    }
                } elseif ($class === 'illuminate\\support\\facades\\event' && $method === 'dispatch' && isset($args[0])) {
                    $this->eventExpression($args[0]->value, $file, $call, $args[1]->value ?? null);
                }
            }
            foreach ($finder->findInstanceOf($nodes, Node\Expr\FuncCall::class) as $call) {
                if ($call->name instanceof Node\Name && strtolower($file->resolvedName($call->name)) === 'event' && isset($call->getArgs()[0])) {
                    $this->eventExpression($call->getArgs()[0]->value, $file, $call, $call->getArgs()[1]->value ?? null);
                }
            }
            $file->releaseAst();
        }
        array_push($this->notices, ...array_slice($this->exposure->notices, $initialExposureNotices));
        ksort($this->entries);
    }

    /** @param array<Node> $nodes */
    private function markRegistrationContext(array $nodes, bool $nested = false): void
    {
        foreach ($nodes as $node) {
            $node->setAttribute('api_nested_registration', $nested);
            $childNested = $nested || ! ($node instanceof Stmt\Namespace_ || $node instanceof Stmt\Declare_ || $node instanceof Stmt\Expression);
            // Calls inherit their enclosing statement's context; their arguments cannot register eagerly.
            foreach ($node->getSubNodeNames() as $property) {
                $child = $node->$property;
                if ($child instanceof Node) {
                    $this->markRegistrationContext([$child], $childNested);
                } elseif (is_array($child)) {
                    $this->markRegistrationContext(array_values(array_filter($child, static fn ($value): bool => $value instanceof Node)), $childNested);
                }
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function returnArray(Stmt\ClassMethod $method, FileContext $file, int $depth = 0): ?array
    {
        $returns = (new NodeFinder)->findInstanceOf($method->stmts ?? [], Stmt\Return_::class);
        if ($depth > 16 || count($returns) !== 1 || ! in_array($returns[0], $method->stmts ?? [], true)) {
            $this->notice($file, 'Conditional schema or schema helper depth limit.');

            return null;
        }
        $expression = $returns[0]->expr;
        if ($expression instanceof Node\Expr\StaticCall && $expression->class instanceof Node\Name && $expression->name instanceof Node\Identifier) {
            $helper = $this->declaration($file->resolvedName($expression->class));
            if ($helper !== null) {
                $target = $this->inheritedMethod($helper[0], $file->resolvedName($expression->class), $expression->name->toString(), $helper[1]);
                if ($target !== null && $target[0]->isStatic()) {
                    return $this->returnArray($target[0], $target[1], $depth + 1);
                }
            }
        }
        if (! $expression instanceof Node\Expr\Array_) {
            $this->notice($file, 'Dynamic schema return in '.$method->name.'.');

            return null;
        }
        $result = [];
        foreach ($expression->items as $item) {
            if ($item === null || $item->unpack || $item->key === null) {
                $this->notice($file, 'Dynamic schema field.');

                continue;
            }
            $key = StaticValue::read($item->key);
            if (! $key['known'] || ! is_string($key['value'])) {
                $this->notice($file, 'Dynamic schema field name.');

                continue;
            }
            $value = StaticValue::read($item->value);
            $variable = isset($method->params[0]) && is_string($method->params[0]->var->name) ? $method->params[0]->var->name : null;
            $result[$key['value']] = $value['known'] ? ['form' => 'literal', 'value' => $value['value']] : $this->schemaChain($item->value, $file, $variable);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function schemaChain(Node\Expr $expr, FileContext $file, ?string $variable): array
    {
        $chain = [];
        $known = true;
        $node = $expr;
        while ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier && count($chain) < 32) {
            $arguments = [];
            $supported = ['string', 'integer', 'number', 'boolean', 'object', 'array', 'null', 'required', 'nullable', 'min', 'max', 'default', 'enum', 'description', 'pattern', 'format', 'items', 'properties', 'additionalProperties', 'minLength', 'maxLength', 'minItems', 'maxItems', 'uniqueItems', 'anyOf', 'oneOf', 'allOf', 'examples', 'title', 'exclusiveMin', 'exclusiveMax', 'multipleOf'];
            if (! in_array($node->name->toString(), $supported, true)) {
                $known = false;
                $this->notice($file, 'Unsupported schema builder method: '.$node->name.'.');
            }
            foreach ($node->getArgs() as $arg) {
                $value = StaticValue::read($arg->value);
                $arguments[] = $value;
                if (! $value['known']) {
                    $known = false;
                    $this->notice($file, 'Dynamic schema argument: '.$node->name.'.');
                }
            }
            array_unshift($chain, ['method' => $node->name->toString(), 'arguments' => $arguments]);
            $node = $node->var;
        }
        if (! $node instanceof Node\Expr\Variable || $variable === null || $node->name !== $variable || $chain === []) {
            $this->notice($file, 'Unsupported schema expression.');

            return ['form' => 'unresolved', 'declaration' => $this->printer->prettyPrintExpr($expr)];
        }

        return ['form' => 'builder', 'chain' => $chain, 'known' => $known];
    }

    private function tool(Stmt\ClassLike $class, string $name, FileContext $file): void
    {
        $toolName = null;
        $unresolvedName = false;
        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (strtolower($file->resolvedName($attribute->name)) === 'laravel\\mcp\\server\\attributes\\name' && isset($attribute->args[0])) {
                    $value = StaticValue::read($attribute->args[0]->value);
                    $toolName = $value['known'] && is_string($value['value']) ? $value['value'] : null;
                    if ($toolName === null) {
                        $unresolvedName = true;
                        $this->notice($file, 'Dynamic MCP name attribute.');
                    }
                }
            }
        }
        $nameProperty = $this->inheritedProperty($class, $name, 'name');
        if ($toolName === null && $nameProperty !== null) {
            $value = StaticValue::read($nameProperty);
            $toolName = $value['known'] && is_string($value['value']) ? $value['value'] : null;
            if ($toolName === null) {
                $unresolvedName = true;
                $this->notice($file, 'Dynamic MCP name property.');
            }
        }
        $input = $output = [];
        foreach (['name', 'schema', 'outputSchema'] as $methodName) {
            $resolved = $this->inheritedMethod($class, $name, $methodName, $file);
            if ($resolved === null) {
                continue;
            }
            [$method, $origin] = $resolved;
            if ($methodName === 'name') {
                $unresolvedName = true;
                $this->notice($origin, 'MCP name() override requires inspection: '.$name);
            } elseif ($methodName === 'schema') {
                $input = $this->returnArray($method, $origin);
            } else {
                $output = $this->returnArray($method, $origin);
            }
        }
        if ($unresolvedName) {
            $toolName = '@unresolved:'.$name;
        } elseif ($toolName === null || $toolName === '') {
            $toolName = Str::kebab($class->name->toString());
        }
        $this->add('mcp:'.$toolName, ['kind' => 'mcp', 'name' => $toolName, 'class' => $name, 'input' => $input, 'output' => $output, 'conditional' => $unresolvedName || $this->php->classes[strtolower($name)]['conditional'], 'ambiguous' => $this->php->classes[strtolower($name)]['ambiguous'],
            'source' => $this->source($class, $file)], $file);
    }

    private function command(string $signature, FileContext $file, Node $node, string $owner): void
    {
        if (strlen($signature) > 10000) {
            $this->notice($file, 'Command signature exceeds the declaration budget.');

            return;
        }
        try {
            [$name, $arguments, $options] = Parser::parse($signature);
            $args = $opts = [];
            foreach ($arguments as $argument) {
                $args[] = ['name' => $argument->getName(), 'required' => $argument->isRequired(), 'array' => $argument->isArray(), 'default' => $argument->getDefault()];
            }
            foreach ($options as $option) {
                $opts[] = ['name' => $option->getName(), 'shortcut' => $option->getShortcut(), 'accepts_value' => $option->acceptValue(),
                    'value_required' => $option->isValueRequired(), 'array' => $option->isArray(), 'negatable' => $option->isNegatable(), 'default' => $option->getDefault()];
            }
            if ($owner === 'callback' && $node->getAttribute('api_nested_registration', false)) {
                $this->notice($file, 'Conditional or nested Artisan callback registration.');
            }
            $this->add('command:'.$name, ['kind' => 'command', 'name' => $name, 'owner' => $owner, 'conditional' => ($this->php->classes[strtolower($owner)]['conditional'] ?? false) || ($owner === 'callback' && $node->getAttribute('api_nested_registration', false)), 'ambiguous' => $this->php->classes[strtolower($owner)]['ambiguous'] ?? false, 'arguments' => $args, 'options' => $opts,
                'source' => $this->source($node, $file)], $file);
        } catch (\InvalidArgumentException $e) {
            $this->notice($file, 'Command signature is invalid: '.$e->getMessage());
        }
    }

    private function publish(Node\Expr\MethodCall $call, FileContext $file): void
    {
        $args = $call->getArgs();
        if (! isset($args[0]) || ! $args[0]->value instanceof Node\Expr\Array_) {
            $this->notice($file, 'Dynamic publication map.');

            return;
        }
        $tag = isset($args[1]) ? StaticValue::read($args[1]->value) : ['known' => true, 'value' => null];
        foreach ($args[0]->value->items as $item) {
            if ($item === null || $item->key === null || $item->unpack) {
                $this->notice($file, 'Dynamic publication item.');

                continue;
            }
            $rawPath = $this->packagePath($item->key, $file);
            $path = $rawPath === null ? null : $this->normalize($rawPath);
            $target = $this->printer->prettyPrintExpr($item->value);
            if ($path === null || ! $tag['known']) {
                $this->notice($file, 'Unresolved published source or tag.');

                continue;
            }
            $migration = $call->name->toString() === 'publishesMigrations' || str_contains($path, 'migrations');
            $kind = $migration ? 'published_migration' : (str_contains($path, 'config/') ? 'published_config' : 'published_file');
            $sources = [];
            foreach ($this->snapshot->files as $candidate => $content) {
                if ($candidate === $path || str_starts_with($candidate, $path.'/')) {
                    $sources[$candidate] = ['hash' => hash('sha256', $content), 'keys' => $kind === 'published_config' ? $this->configKeys($candidate, $content) : []];
                }
            }
            if ($sources === []) {
                $this->notice($file, 'Published source unavailable: '.$path);
            }
            $this->add('published:'.hash('sha256', serialize([$tag['value'], $target])), ['kind' => $kind, 'name' => $target,
                'tag' => $tag['value'], 'package_path' => $path, 'files' => $sources, 'source' => $this->source($call, $file)], $file);
        }
    }

    /** @return list<string> */
    private function configKeys(string $path, string $content): array
    {
        $nodes = (new FileContext($path, $content))->ast();
        $keys = [];
        $visit = function (Node\Expr\Array_ $array, string $prefix) use (&$visit, &$keys): void {
            foreach ($array->items as $item) {
                if ($item?->key instanceof Node\Scalar\String_) {
                    $key = $prefix.$item->key->value;
                    $keys[] = $key;
                    if ($item->value instanceof Node\Expr\Array_) {
                        $visit($item->value, $key.'.');
                    }
                }
            }
        };
        foreach ($nodes ?? [] as $node) {
            if ($node instanceof Stmt\Return_ && $node->expr instanceof Node\Expr\Array_) {
                $visit($node->expr, '');
            }
        }

        return $keys;
    }

    private function packagePath(Node\Expr $expr, FileContext $file): ?string
    {
        if ($expr instanceof Node\Scalar\MagicConst\Dir) {
            return dirname($file->path);
        }
        if ($expr instanceof Node\Scalar\MagicConst\File) {
            return $file->path;
        }
        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            $left = $this->packagePath($expr->left, $file);
            $right = $this->packagePath($expr->right, $file);
            if ($left !== null && $right !== null) {
                return $this->normalize($left.$right);
            }
        }
        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name && strtolower($file->resolvedName($expr->name)) === 'dirname' && isset($expr->getArgs()[0])) {
            $args = $expr->getArgs();
            $path = $this->packagePath($args[0]->value, $file);
            $levels = isset($args[1]) ? StaticValue::read($args[1]->value) : ['known' => true, 'value' => 1];
            if ($path !== null && $levels['known'] && is_int($levels['value']) && $levels['value'] > 0 && $levels['value'] < 10) {
                return dirname($path, $levels['value']);
            }
        }
        $value = StaticValue::read($expr);

        return $value['known'] && is_string($value['value']) ? $value['value'] : null;
    }

    private function normalize(string $path): ?string
    {
        if (str_starts_with($path, '/') || str_contains($path, '\\')) {
            return null;
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    private function eventExpression(Node\Expr $expr, FileContext $file, Node $site, ?Node\Expr $payloadExpression = null): void
    {
        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            $this->event($file->resolvedName($expr->class), $file, $site);

            return;
        }
        $value = StaticValue::read($expr);
        if ($value['known'] && is_string($value['value'])) {
            $payload = $payloadExpression === null ? ['known' => true, 'value' => []] : StaticValue::read($payloadExpression);
            if (! $payload['known']) {
                $this->notice($file, 'Dynamic payload for named event: '.$value['value']);
            }
            $this->add('event:'.$value['value'], ['kind' => 'event', 'name' => $value['value'], 'identity_kind' => 'string',
                'payload' => $payload, 'conditional' => ! $payload['known'], 'source' => $this->source($site, $file)], $file);
        } else {
            $this->notice($file, 'Dynamic event identity.');
        }
    }

    private function event(string $name, FileContext $file, Node $node): void
    {
        $id = 'event:'.$name;
        $payload = [];
        foreach ($this->exposure->exposedMembers($name) as $key => $member) {
            if ($member['visibility'] === 'public' && ($member['kind'] === 'property' || $key === 'method:__construct')) {
                $payload[$key] = array_diff_key($member, array_flip(['source', 'body', 'internal']));
            }
        }
        $entry = ['kind' => 'event', 'name' => $name, 'payload' => $payload, 'source' => $this->source($node, $file)];
        if (! isset($this->entries[$id])) {
            $this->entries[$id] = $entry;
        }
    }

    private function inherits(string $class, string $parent, int $depth = 0): bool
    {
        if ($depth > 32) {
            return false;
        }
        $base = $this->php->classes[strtolower($class)]['parent'] ?? null;

        return is_string($base) && (strcasecmp($base, $parent) === 0 || $this->inherits($base, $parent, $depth + 1));
    }

    /** @return array{Stmt\ClassLike, FileContext}|null */
    private function declaration(string $name): ?array
    {
        $path = $this->php->classes[strtolower($name)]['source']['path'] ?? null;
        if (! is_string($path) || ! isset($this->snapshot->files[$path])) {
            return null;
        }
        $source = $this->snapshot->files[$path];
        $ceiling = MemoryLimit::bytes();
        if ($ceiling !== null && memory_get_usage(true) + strlen($source) * 40 > $ceiling * 0.75) {
            return null;
        }
        $file = new FileContext($path, $source);
        foreach ((new NodeFinder)->findInstanceOf($file->ast() ?? [], Stmt\ClassLike::class) as $class) {
            if (isset($class->namespacedName) && strcasecmp($class->namespacedName->toString(), $name) === 0) {
                return [$class, $file];
            }
        }

        return null;
    }

    private function inheritedProperty(Stmt\ClassLike $class, string $owner, string $property, int $depth = 0): ?Node\Expr
    {
        foreach ($class->getProperties() as $statement) {
            foreach ($statement->props as $prop) {
                if ($prop->name->toString() === $property) {
                    return $prop->default;
                }
            }
        }
        $entry = $this->exposure->exposedMembers($owner)['property:'.$property] ?? null;
        $declaration = $entry === null ? null : $this->declaration($entry['declared_in']);
        if ($declaration !== null && strcasecmp($entry['declared_in'], $owner) !== 0) {
            foreach ($declaration[0]->getProperties() as $statement) {
                foreach ($statement->props as $prop) {
                    if ($prop->name->toString() === $property) {
                        return $prop->default;
                    }
                }
            }
        }
        $parent = $this->php->classes[strtolower($owner)]['parent'] ?? null;
        $origin = $depth < 32 && is_string($parent) ? $this->declaration($parent) : null;

        return $origin === null ? null : $this->inheritedProperty($origin[0], $parent, $property, $depth + 1);
    }

    /** @return array{Stmt\ClassMethod, FileContext}|null */
    private function inheritedMethod(Stmt\ClassLike $class, string $owner, string $name, FileContext $file, int $depth = 0): ?array
    {
        foreach ($class->getMethods() as $method) {
            if (strcasecmp($method->name->toString(), $name) === 0) {
                return [$method, $file];
            }
        }
        $entry = $this->exposure->exposedMembers($owner)['method:'.strtolower($name)] ?? null;
        $declaration = $entry === null ? null : $this->declaration($entry['declared_in']);
        if ($declaration !== null && strcasecmp($entry['declared_in'], $owner) !== 0) {
            foreach ($declaration[0]->getMethods() as $method) {
                if ($method->getStartLine() === $entry['source']['line']) {
                    return [$method, $declaration[1]];
                }
            }
        }
        $parent = $this->php->classes[strtolower($owner)]['parent'] ?? null;
        $origin = $depth < 32 && is_string($parent) ? $this->declaration($parent) : null;

        return $origin === null ? null : $this->inheritedMethod($origin[0], $parent, $name, $origin[1], $depth + 1);
    }

    /** @param array<string, mixed> $entry */
    private function add(string $id, array $entry, FileContext $file): void
    {
        if (isset($this->entries[$id])) {
            if (($entry['identity_kind'] ?? null) === 'string'
                && array_diff_key($this->entries[$id], ['source' => true]) === array_diff_key($entry, ['source' => true])) {
                return;
            }
            $this->entries[$id]['ambiguous'] = true;
            $this->notice($file, 'Duplicate framework contract identity: '.$id);
        } else {
            $this->entries[$id] = $entry;
        }
    }

    /** @return array{path: string, line: int} */
    private function source(Node $node, FileContext $file): array
    {
        return ['path' => $file->path, 'line' => $node->getStartLine()];
    }

    private function notice(FileContext $file, string $message): void
    {
        $this->notices[] = ['code' => 'E_FRAMEWORK_CONTRACT_UNRESOLVED', 'path' => $file->path, 'message' => $message];
    }
}
