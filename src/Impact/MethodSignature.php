<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Text as Standard;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use InvalidArgumentException;

/** Serializable declaration facts and a parser that never evaluates a proposal. */
final class MethodSignature
{
    /** @return array<string, mixed> */
    public static function extract(Node $method, string $class = '', ?string $parent = null): array
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
            $nodes = $file->syntax();
            if (count($nodes ?? []) !== 1 || ! Node::is($nodes[0], 'statement.class') || count($nodes[0]->stmts) !== 1 || ! Node::is($nodes[0]->stmts[0], 'statement.class-method')) {
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

    public static function type(Node|string|null $type, string $class, ?string $parent): ?string
    {
        if ($type === null) {
            return null;
        }
        if (Node::is($type, 'nullable-type')) {
            return '?'.self::type($type->type, $class, $parent);
        }
        if (Node::is($type, 'union-type') || Node::is($type, 'intersection-type')) {
            $parts = array_map(static fn (Node $part): string => self::type($part, $class, $parent) ?? '', $type->types);
            sort($parts);

            return '('.implode(Node::is($type, 'union-type') ? '|' : '&', $parts).')';
        }
        if (Node::is($type, 'name')) {
            $resolved = $type->getAttribute('resolvedName');

            return match (strtolower($type->toString())) {
                'self' => strtolower($class), 'parent' => strtolower($parent ?? '@unknown-parent'),
                'static' => '@static:'.strtolower($class),
                default => strtolower((Node::is($resolved, 'name') ? $resolved : $type)->toString()),
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
    public static function site(Node $call, string $class, bool $staticContext): array
    {
        $form = Node::is($call, 'expression.new') ? 'new' : (Node::is($call, 'expression.static-call') ? (Node::is($call->class, 'name') && in_array(strtolower($call->class->toString()), ['self', 'parent', 'static'], true) ? strtolower($call->class->toString()) : 'class') : 'instance');
        $arguments = [];
        foreach ($call->isFirstClassCallable() ? [] : $call->getArgs() as $arg) {
            $value = $arg->value;
            $ref = Node::is($value, 'expression.variable') || Node::is($value, 'expression.property-fetch') || Node::is($value, 'expression.static-property-fetch') || Node::is($value, 'expression.array-dim-fetch') ? 'variable' : (Node::is($value, 'expression.call-like') && ! Node::is($value, 'expression.new') ? 'unknown' : 'value');
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
