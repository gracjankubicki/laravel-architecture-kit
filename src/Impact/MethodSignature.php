<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\PrettyPrinter\Standard;

/** Serializable declaration facts and a parser that never evaluates a proposal. */
final class MethodSignature
{
    /** @return array<string, mixed> */
    public static function extract(Stmt\ClassMethod $method, string $class = '', ?string $parent = null): array
    {
        $printer = new Standard;
        $params = [];
        $lastRequired = -1;
        foreach ($method->params as $i => $param) {
            if ($param->default === null && ! $param->variadic) {
                $lastRequired = $i;
            }
        }
        foreach ($method->params as $i => $param) {
            $params[] = ['name' => is_string($param->var->name) ? $param->var->name : '',
                'optional' => $param->variadic || ($param->default !== null && $i > $lastRequired),
                'default' => $param->default === null ? null : $printer->prettyPrintExpr($param->default),
                'variadic' => $param->variadic, 'by_ref' => $param->byRef,
                'type' => self::type($param->type, $class, $parent), 'promoted' => $param->flags !== 0];
        }

        return ['parameters' => $params, 'visibility' => $method->isPrivate() ? 'private' : ($method->isProtected() ? 'protected' : 'public'),
            'static' => $method->isStatic(), 'abstract' => $method->isAbstract(), 'final' => $method->isFinal(),
            'return_type' => self::type($method->returnType, $class, $parent), 'return_by_ref' => $method->byRef];
    }

    /** @param array<string, mixed> $original
     * @return array<string, mixed> */
    public static function parse(string $proposal, string $name, array $original, string $class = '', ?string $parent = null): array
    {
        if (strlen($proposal) > 10000 || trim($proposal) === '') {
            throw new InvalidArgumentException('Provide a single PHP method declaration of at most 10000 bytes.');
        }
        $proposal = rtrim(trim($proposal), ';');
        if (! preg_match('/^(?:(?:public|protected|private|static|abstract|final)\s+)*function\b/i', $proposal)) {
            $proposal = $original['visibility'].($original['static'] ? ' static' : '').' function '.$proposal;
        }
        $file = new FileContext('(proposal)', '<?php class SignatureProposal { '.$proposal.' {} }');
        try {
            $nodes = $file->ast();
            if (count($nodes ?? []) !== 1 || ! $nodes[0] instanceof Stmt\Class_ || count($nodes[0]->stmts) !== 1 || ! $nodes[0]->stmts[0] instanceof Stmt\ClassMethod) {
                throw new InvalidArgumentException('Proposal must contain one method declaration without a body.');
            }
            $method = $nodes[0]->stmts[0];
            if (strcasecmp($method->name->toString(), $name) !== 0 || $method->stmts !== [] || $method->attrGroups !== [] || $method->isAbstract() || $method->isFinal()) {
                throw new InvalidArgumentException('Use the selected method name, no body, attributes, abstract or final modifier.');
            }
            $seen = [];
            foreach ($method->params as $i => $param) {
                if (! is_string($param->var->name) || isset($seen[$param->var->name]) || $param->attrGroups !== [] || ($param->variadic && ($i !== count($method->params) - 1 || $param->default !== null)) || ($param->flags !== 0 && (strtolower($name) !== '__construct' || $param->variadic))) {
                    throw new InvalidArgumentException('Invalid, duplicate or unsupported parameter declaration.');
                }
                $seen[$param->var->name] = true;
            }
            $result = self::extract($method, $class, $parent);
            $result['abstract'] = $original['abstract'];
            $result['final'] = $original['final'];

            return $result;
        } finally {
            $file->releaseAst();
        }
    }

    private static function type(Node|string|null $type, string $class, ?string $parent): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof Node\NullableType) {
            return '?'.self::type($type->type, $class, $parent);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $parts = array_map(static fn (Node $part): string => self::type($part, $class, $parent) ?? '', $type->types);
            sort($parts);

            return '('.implode($type instanceof Node\UnionType ? '|' : '&', $parts).')';
        }
        if ($type instanceof Node\Name) {
            $resolved = $type->getAttribute('resolvedName');

            return match (strtolower($type->toString())) {
                'self' => strtolower($class), 'parent' => strtolower($parent ?? '@unknown-parent'),
                'static' => '@static:'.strtolower($class),
                default => strtolower(($resolved instanceof Node\Name ? $resolved : $type)->toString()),
            };
        }

        return strtolower((string) $type);
    }

    public static function valid(mixed $data): bool
    {
        if (! is_array($data) || ! is_array($data['parameters'] ?? null) || ! array_is_list($data['parameters']) || ! in_array($data['visibility'] ?? null, ['public', 'protected', 'private'], true)) {
            return false;
        }
        foreach (['static', 'abstract', 'final', 'return_by_ref'] as $key) {
            if (! is_bool($data[$key] ?? null)) {
                return false;
            }
        }
        if (! array_key_exists('return_type', $data) || ($data['return_type'] !== null && ! is_string($data['return_type']))) {
            return false;
        }
        foreach ($data['parameters'] as $param) {
            if (! is_array($param) || ! is_string($param['name'] ?? null)) {
                return false;
            }
            foreach (['optional', 'variadic', 'by_ref', 'promoted'] as $key) {
                if (! is_bool($param[$key] ?? null)) {
                    return false;
                }
            }
            foreach (['type', 'default'] as $key) {
                if (! array_key_exists($key, $param) || ($param[$key] !== null && ! is_string($param[$key]))) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    public static function site(Node\Expr\CallLike $call, string $class, bool $staticContext): array
    {
        $form = $call instanceof Node\Expr\New_ ? 'new' : ($call instanceof Node\Expr\StaticCall ? ($call->class instanceof Node\Name && in_array(strtolower($call->class->toString()), ['self', 'parent', 'static'], true) ? strtolower($call->class->toString()) : 'class') : 'instance');
        $arguments = [];
        foreach ($call->isFirstClassCallable() ? [] : $call->getArgs() as $arg) {
            $value = $arg->value;
            $ref = $value instanceof Node\Expr\Variable || $value instanceof Node\Expr\PropertyFetch || $value instanceof Node\Expr\StaticPropertyFetch || $value instanceof Node\Expr\ArrayDimFetch ? 'variable' : ($value instanceof Node\Expr\CallLike && ! $value instanceof Node\Expr\New_ ? 'unknown' : 'value');
            $arguments[] = ['name' => $arg->name?->toString(), 'unpack' => $arg->unpack, 'reference' => $ref];
        }

        return ['form' => $form, 'class' => $class, 'static_context' => $staticContext, 'arguments' => $arguments];
    }

    public static function validSite(mixed $data): bool
    {
        if (! is_array($data) || ! in_array($data['form'] ?? null, ['new', 'class', 'self', 'parent', 'static', 'instance', 'reference'], true) || ! is_string($data['class'] ?? null) || ! is_bool($data['static_context'] ?? null) || ! is_array($data['arguments'] ?? null) || ! array_is_list($data['arguments'])) {
            return false;
        }
        foreach ($data['arguments'] as $arg) {
            if (! is_array($arg) || ! array_key_exists('name', $arg) || ($arg['name'] !== null && ! is_string($arg['name'])) || ! is_bool($arg['unpack'] ?? null) || ! in_array($arg['reference'] ?? null, ['variable', 'value', 'unknown'], true)) {
                return false;
            }
        }

        return true;
    }
}
