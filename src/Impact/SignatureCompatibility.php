<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

/** Known PHP argument and declaration rules, deliberately not a type analyser. */
final class SignatureCompatibility
{
    /** @param array<string, mixed> $signature
     * @param array<string, mixed> $site */
    public static function extraPositionalArguments(array $signature, array $site): bool
    {
        $params = $signature['parameters'];
        if ($params !== [] && $params[count($params) - 1]['variadic']) {
            return false;
        }

        return count(array_filter($site['arguments'], static fn (array $arg): bool => $arg['name'] === null && ! $arg['unpack'])) > count($params);
    }

    /** @param array<string, mixed> $signature
     * @param array<string, mixed> $site
     * @return list<string> */
    public static function argumentErrors(array $signature, array $site): array
    {
        $params = $signature['parameters'];
        $names = array_column($params, 'name');
        $variadic = $params !== [] && $params[count($params) - 1]['variadic'];
        $assigned = [];
        $position = 0;
        $errors = [];
        $named = false;
        foreach ($site['arguments'] as $arg) {
            if ($arg['unpack']) {
                return [];
            }
            if ($arg['name'] !== null) {
                $named = true;
                $index = array_search($arg['name'], $names, true);
                if ($index === false) {
                    if (! $variadic) {
                        $errors[] = 'unknown_named_argument:'.$arg['name'];
                    }
                    $index = $variadic ? count($params) - 1 : null;
                }
            } else {
                if ($named) {
                    $errors[] = 'positional_argument_after_named';
                }
                $index = $position++;
                if ($index >= count($params)) {
                    $index = $variadic ? count($params) - 1 : null;
                }
            }
            if ($index === null) {
                continue;
            }
            if (isset($assigned[$index]) && ! $params[$index]['variadic']) {
                $errors[] = 'argument_already_assigned:'.$params[$index]['name'];
            }
            $assigned[$index] = true;
            if ($params[$index]['by_ref'] && $arg['reference'] === 'value') {
                $errors[] = 'argument_not_reference:'.$params[$index]['name'];
            }
        }
        foreach ($params as $i => $param) {
            if (! $param['optional'] && ! isset($assigned[$i])) {
                $errors[] = 'missing_required_argument:'.$param['name'];
            }
        }

        return array_values(array_unique($errors));
    }

    /** @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return list<string> */
    public static function semanticChanges(array $before, array $after): array
    {
        $changes = [];
        if (count($after['parameters']) < count($before['parameters'])) {
            $changes[] = 'Removed parameters may change behaviour even when PHP accepts extra positional arguments.';
        }
        foreach ($after['parameters'] as $i => $param) {
            $previous = $before['parameters'][$i] ?? null;
            if ($previous === null) {
                if ($param['type'] !== null || $param['promoted']) {
                    $changes[] = 'New parameter type or promoted property needs inspection.';
                }

                continue;
            }
            if ($param['type'] !== $previous['type']) {
                $changes[] = 'Parameter type changed; runtime value compatibility is not proved.';
            }
            if ($param['default'] !== $previous['default']) {
                $changes[] = 'Default value changed; behaviour needs inspection.';
            }
            if ($param['name'] !== $previous['name'] || $param['promoted'] !== $previous['promoted']) {
                $changes[] = 'Parameter name or promotion changed; inspect the method body and named callers.';
            }
            if ($param['by_ref'] !== $previous['by_ref']) {
                $changes[] = 'Reference semantics changed; inspect mutations and reference-returning arguments.';
            }
            if ($param['variadic'] !== $previous['variadic']) {
                $changes[] = 'Variadic argument handling changed; inspect the method body.';
            }
        }
        if ($after['return_type'] !== $before['return_type'] || $after['return_by_ref'] !== $before['return_by_ref']) {
            $changes[] = 'Return type or return-by-reference changed; inspect callers and the method body.';
        }
        if ($after['static'] !== $before['static']) {
            $changes[] = 'Static context changed; inspect use of $this in the method body.';
        }

        return array_values(array_unique($changes));
    }

    /** Child must continue to accept the parent contract. Constructor exceptions
     * are handled by the caller using the declaration kind.
     *
     * @param array<string, mixed> $parent
     * @param array<string, mixed> $child
     * @return array{errors: list<string>, uncertain: list<string>} */
    public static function contract(array $parent, array $child, bool $privateTraitRequirement = false): array
    {
        $errors = $uncertain = [];
        if ($parent['visibility'] === 'private' && ! $privateTraitRequirement) {
            return ['errors' => [], 'uncertain' => []];
        }
        if ($parent['static'] !== $child['static']) {
            $errors[] = 'contract_static_mismatch';
        }
        if (self::visibility($child['visibility']) < self::visibility($parent['visibility'])) {
            $errors[] = 'contract_visibility_narrowed';
        }
        $required = static fn (array $params): int => count(array_filter($params, static fn (array $p): bool => ! $p['optional']));
        if ($required($child['parameters']) > $required($parent['parameters'])) {
            $errors[] = 'contract_required_parameter_added';
        }
        $childVariadic = $child['parameters'] !== [] && $child['parameters'][count($child['parameters']) - 1]['variadic'];
        $parentVariadic = $parent['parameters'] !== [] && $parent['parameters'][count($parent['parameters']) - 1]['variadic'];
        if (! $childVariadic && (count($child['parameters']) < count($parent['parameters']) || $parentVariadic)) {
            $errors[] = 'contract_parameter_capacity_reduced';
        }
        foreach ($parent['parameters'] as $i => $param) {
            $actual = $child['parameters'][$i] ?? ($childVariadic ? $child['parameters'][count($child['parameters']) - 1] : null);
            if ($actual === null) {
                continue;
            }
            if ($param['by_ref'] !== $actual['by_ref']) {
                $errors[] = 'contract_parameter_reference_mismatch:'.$param['name'];
            }
            if ($param['type'] === null && $actual['type'] !== null && strtolower($actual['type']) !== 'mixed') {
                $errors[] = 'contract_untyped_parameter_restricted:'.$param['name'];
            } elseif ($param['type'] !== $actual['type'] && $actual['type'] !== null && strtolower($actual['type']) !== 'mixed') {
                $uncertain[] = 'Parameter type variance needs inspection.';
            }
        }
        if ($parent['return_type'] !== null && $child['return_type'] === null) {
            $errors[] = 'contract_return_type_removed';
        } elseif ($parent['return_type'] !== null && $parent['return_type'] !== $child['return_type'] && strtolower($parent['return_type']) !== 'mixed') {
            $uncertain[] = 'Return type variance needs inspection.';
        }
        if ($parent['return_by_ref'] !== $child['return_by_ref']) {
            $uncertain[] = 'Return reference compatibility needs inspection.';
        }

        return ['errors' => array_values(array_unique($errors)), 'uncertain' => array_values(array_unique($uncertain))];
    }

    public static function visibility(string $value): int
    {
        return match ($value) {
            'public' => 2, 'protected' => 1, default => 0,
        };
    }
}
