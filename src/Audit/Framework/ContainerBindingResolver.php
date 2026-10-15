<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Framework;

use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Finder as NodeFinder;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Node;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceClass;

/** Reads factory returns without invoking the factory or claiming a dynamic result. */
final class ContainerBindingResolver
{
    private int $visits = 0;

    /** @return list<string> */
    public function contracts(FrameworkValue $factory): array
    {
        if ($factory->callback === null || $factory->callbackSource === null) {
            return [];
        }

        return $this->names($factory->callback->returnType, $factory->callbackSource);
    }

    /** @return list<string> */
    private function names(?Node $type, SourceClass $source): array
    {
        if (Node::is($type, 'nullable-type')) {
            return $this->names($type->type, $source);
        }
        if (Node::is($type, 'name')) {
            return in_array(strtolower($type->toString()), ['self', 'static', 'parent'], true) ? [] : [$source->file->resolvedName($type)];
        }
        if (Node::is($type, 'union-type')) {
            $names = [];
            foreach ($type->types as $member) {
                array_push($names, ...$this->names($member, $source));
            }

            return array_values(array_unique($names));
        }

        return [];
    }

    public function resolve(FrameworkValue $factory): FrameworkValue
    {
        $callback = $factory->callback;
        $source = $factory->callbackSource;
        if ($callback === null || $source === null) {
            return FrameworkValue::unknown();
        }
        $variables = Node::is($callback, 'expression.arrow-function') ? $factory->captures : [];
        if (Node::is($callback, 'expression.closure')) {
            foreach ($callback->uses as $use) {
                if (is_string($use->var->name)) {
                    $variables[$use->var->name] = $use->byRef ? FrameworkValue::unknown() : ($factory->captures[$use->var->name] ?? FrameworkValue::unknown());
                }
            }
        }
        foreach ($callback->params as $param) {
            if (is_string($param->var->name)) {
                $variables[$param->var->name] = FrameworkValue::unknown();
            }
        }
        $this->visits = 0;
        if (Node::is($callback, 'expression.arrow-function')) {
            return $this->expression($callback->expr, $source, $variables);
        }

        return $this->statements($callback->stmts, $source, $variables);
    }

    /** @param list<Node> $statements
     * @param  array<string, FrameworkValue|null>  $variables
     */
    private function statements(array $statements, SourceClass $source, array $variables): FrameworkValue
    {
        foreach ($statements as $index => $statement) {
            if (++$this->visits > 128) {
                return FrameworkValue::unknown();
            }
            if (Node::is($statement, 'statement.return')) {
                return $statement->expr !== null ? $this->expression($statement->expr, $source, $variables) : FrameworkValue::unknown();
            }
            if (Node::is($statement, 'statement.expression') && Node::is($statement->expr, 'expression.assign') && Node::is($statement->expr->var, 'expression.variable') && is_string($statement->expr->var->name)) {
                $variables = $this->conditionVariables($statement->expr->expr, $variables);
                $variables[$statement->expr->var->name] = $this->expression($statement->expr->expr, $source, $variables);

                continue;
            }
            if (Node::is($statement, 'statement.if')) {
                $variables = $this->conditionVariables($statement->cond, $variables);
                $rest = array_slice($statements, $index + 1);
                $branches = [$this->statements([...$statement->stmts, ...$rest], $source, $variables)];
                foreach ($statement->elseifs as $branch) {
                    $variables = $this->conditionVariables($branch->cond, $variables);
                    $branches[] = $this->statements([...$branch->stmts, ...$rest], $source, $variables);
                }
                $branches[] = $this->statements([...($statement->else->stmts ?? []), ...$rest], $source, $variables);

                return $this->merge($branches);
            }

            // Other statements can mutate captured variables, skip returns or throw.
            return FrameworkValue::unknown();
        }

        return FrameworkValue::unknown();
    }

    /** @param array<string, FrameworkValue|null> $variables */
    private function expression(Node $expression, SourceClass $source, array $variables): FrameworkValue
    {
        if (++$this->visits > 128) {
            return FrameworkValue::unknown();
        }
        if (Node::is($expression, 'expression.new') && Node::is($expression->class, 'name')) {
            return FrameworkValue::type($source->file->resolvedName($expression->class));
        }
        if (Node::is($expression, 'expression.variable') && is_string($expression->name)) {
            return $variables[$expression->name] ?? FrameworkValue::unknown();
        }
        if (Node::is($expression, 'expression.ternary')) {
            $variables = $this->conditionVariables($expression->cond, $variables);

            return $this->merge([
                $this->expression($expression->if ?? $expression->cond, $source, $variables),
                $this->expression($expression->else, $source, $variables),
            ]);
        }
        if (Node::is($expression, 'expression.match')) {
            $variables = $this->conditionVariables($expression->cond, $variables);
            $results = [];
            foreach ($expression->arms as $arm) {
                foreach ($arm->conds ?? [] as $condition) {
                    $variables = $this->conditionVariables($condition, $variables);
                }
                $results[] = $this->expression($arm->body, $source, $variables);
            }

            return $this->merge($results);
        }

        return FrameworkValue::unknown();
    }

    /** @param list<FrameworkValue> $values */
    private function merge(array $values): FrameworkValue
    {
        $types = [];
        $unknown = false;
        foreach ($values as $value) {
            $unknown = $unknown || $value->isUnknown();
            array_push($types, ...$value->typeNames());
        }
        $types = array_values(array_unique($types));
        if (count($types) > 4) {
            return FrameworkValue::unknown();
        }

        return new FrameworkValue(possibleTypes: $types, unknown: $unknown, ambiguous: $unknown || count($types) > 1);
    }

    /** @param array<string, FrameworkValue|null> $variables
     * @return array<string, FrameworkValue|null>
     */
    private function conditionVariables(Node $condition, array $variables): array
    {
        $mutation = (new NodeFinder)->findFirst($condition, fn (Node $node): bool => Node::is($node, 'expression.assign')
            || Node::is($node, 'expression.assign-ref') || Node::is($node, 'expression.assign-op')
            || Node::is($node, 'expression.pre-inc') || Node::is($node, 'expression.post-inc')
            || Node::is($node, 'expression.pre-dec') || Node::is($node, 'expression.post-dec')
            || Node::is($node, 'expression.func-call') || Node::is($node, 'expression.method-call')
            || Node::is($node, 'expression.static-call')
            || (Node::is($node, 'expression.new') && $node->args !== []));
        if ($mutation !== null) {
            return array_map(fn (): FrameworkValue => FrameworkValue::unknown(), $variables);
        }

        return $variables;
    }
}
