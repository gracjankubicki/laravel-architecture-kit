<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ExecutionLinks;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Container resolution triggers validation, rather than every method of a request. */
final class CatalogFormRequestResolver
{
    private int $ruleVisits = 0;

    /** @var array<string, list<array<string, mixed>>> */
    private array $receiverReturns = [];

    /** @var list<int> */
    private array $frameworkMajors = [12, 13];

    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    /** @param list<array<string, mixed>> $routes */
    public function resolve(array $routes, ExecutionLinks $links): void
    {
        $this->receiverReturns = [];
        $versions = [];
        foreach ($this->index->elements as $element) {
            if ($element['kind'] === 'composer-package' && in_array($element['name'], ['laravel/framework', 'illuminate/validation'], true)
                && is_string($element['metadata']['normalized_version'] ?? null)
                && preg_match('/^(12|13)\./', $element['metadata']['normalized_version'], $matches) === 1) {
                $versions[] = (int) $matches[1];
            }
        }
        $this->frameworkMajors = $versions === [] ? [12, 13] : array_values(array_unique($versions));
        $visits = 0;
        foreach ($routes as $route) {
            $handler = $route['handler'];
            $parameters = [];
            if (isset($handler['class'], $handler['method'])) {
                $parameters = $links->method($handler['class'], $handler['method'])['parameters'] ?? [];
            } elseif (isset($handler['callback'], $this->index->elements[$handler['callback']])) {
                $parameters = $this->index->elements[$handler['callback']]['metadata']['parameters'] ?? [];
            }
            foreach ($parameters as $parameter) {
                foreach ($parameter['types'] as $type) {
                    if (++$visits > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
                        $this->notice($route, 'FormRequest composition reached its parameter or memory budget.');

                        return;
                    }
                    if (! $links->inherits($type, 'Illuminate\\Foundation\\Http\\FormRequest')) {
                        continue;
                    }
                    if (count($parameter['types']) !== 1 || ($parameter['route_resolvable'] ?? true) === false) {
                        $this->notice($route, 'FormRequest parameter is not a single container-resolvable type.');

                        continue;
                    }
                    $ids = $this->index->namedTypes($type);
                    if (count($ids) !== 1) {
                        $this->notice($route, 'FormRequest declaration is absent or ambiguous.');

                        continue;
                    }
                    $this->edge($route['id'], $ids[0], 'http-form-request', $route['source'], ['request_type' => $type, 'conditions' => ['The route handler is selected and the container resolves this parameter.']]);
                    $this->hooks($type, $ids[0], $route, $links);
                }
            }
        }
        $this->rules($links);
    }

    private function rules(ExecutionLinks $links): void
    {
        $owners = [];
        foreach ($this->index->relations as $row) {
            if ($row['kind'] === 'form-request-hook' && ($row['metadata']['method'] ?? null) === 'rules') {
                $owners[$row['to']] = 'resolved-request';
            }
            if ($row['kind'] === 'validation-site-registration') {
                $site = $this->index->elements[$row['to']];
                $operation = $site['metadata']['operation'];
                $receiver = $site['metadata']['receiver_type'];
                $confirmed = [];
                $receiverSources = [];
                if ($receiver !== null) {
                    $candidates = $this->calls->receiverCandidates($receiver);
                    $receiverSources = $candidates['sources'];
                    if ($candidates['limited'] || $candidates['types'] === [] && str_starts_with($receiver, '@')) {
                        $this->notice(['id' => $row['from'], 'source' => $row], 'Validation receiver is unresolved or reached its source traversal budget.');
                    }
                    $method = match ($operation) {
                        'request-validatewithbag' => 'validatewithbag',
                        'factory-make' => 'make',
                        default => 'validate',
                    };
                    foreach ($candidates['types'] as $type) {
                        $request = $links->inherits($type, 'Illuminate\\Http\\Request') || $links->inherits($type, 'Illuminate\\Foundation\\Http\\FormRequest');
                        $factory = $links->inherits($type, 'Illuminate\\Contracts\\Validation\\Factory') || $links->inherits($type, 'Illuminate\\Validation\\Factory');
                        $valid = in_array($operation, ['method-validate', 'request-validatewithbag'], true) ? $request : $factory;
                        if (! $valid) {
                            continue;
                        }
                        if ($links->method($type, $method) !== null) {
                            $this->notice(['id' => $row['from'], 'source' => $row], 'Source validation method overrides its framework contract; default rules are not inferred.');

                            continue;
                        }
                        $confirmed[] = $type;
                    }
                    $this->index->elements[$row['to']]['metadata']['framework_contract_resolved'] = $confirmed !== [];
                    $this->index->elements[$row['to']]['metadata']['receiver_candidates'] = $candidates['types'];
                    if ($confirmed === []) {
                        continue;
                    }
                }
                $shadowed = $this->index->namedTypes('Illuminate\\Support\\Facades\\Validator') !== [];
                foreach ($site['metadata']['helper_names'] as $name) {
                    foreach ($this->index->names[strtolower($name)] ?? [] as $id) {
                        $shadowed = $shadowed || $this->index->elements[$id]['kind'] === 'function';
                    }
                }
                if ($shadowed) {
                    $this->notice(['id' => $row['from'], 'source' => $row], 'Validation API is shadowed by a source declaration; framework behavior is not inferred.');

                    continue;
                }
                $mode = in_array($operation, ['validate', 'method-validate', 'request-validatewithbag', 'factory-validate'], true) ? 'invokes-validation' : 'constructs-validator';
                $owners[$row['to']] = $mode;
                $this->edge($row['from'], $row['to'], $mode === 'invokes-validation' ? 'invokes-validation' : 'constructs-validator', $row,
                    ['validation_mode' => $mode, 'receiver_candidates' => $confirmed, 'receiver_sources' => $receiverSources, 'conditions' => [$mode === 'invokes-validation' ? 'The source call reaches a receiver with its default validation contract; union alternatives and nullsafe branches are not runtime proof.' : 'The call constructs a validator; its rules run only if validation is requested later.']]);
            }
        }
        foreach ($this->index->relations as $row) {
            if ($row['kind'] !== 'validator-instance-candidate' || ($owners[$row['to']] ?? null) !== 'constructs-validator') {
                continue;
            }
            $method = $row['metadata']['validator_method'];
            $condition = in_array($method, ['validated', 'safe'], true)
                ? 'The default validator runs its rules if its messages have not yet been computed.'
                : 'The source call requests validation on the validator constructed at the target site.';
            $this->edge($row['from'], $row['to'], 'invokes-validation', $row,
                ['validation_mode' => 'invokes-validation', 'validator_method' => $method, 'conditions' => [$condition]]);
        }
        $this->ruleVisits = 0;
        foreach ($this->index->relations as $row) {
            if (in_array($row['kind'], ['returned-rule-builder-callback', 'returned-rule-builder-callable'], true) && isset($owners[$row['from']]) && $this->frameworkRuleAllowed($row)) {
                if (! $this->ruleBudget($row)) {
                    return;
                }
                $targets = [$row['to']];
                if ($row['kind'] === 'returned-rule-builder-callable') {
                    $candidates = $this->calls->sourceCallableCandidates($row['metadata']['callback_receiver'], $row['metadata']['callback_method'], $row['metadata']['callback_exact'], $row['metadata']['factory_call']);
                    $targets = $candidates['targets'];
                    if ($targets === [] || $candidates['limited']) {
                        $this->notice(['id' => $row['from'], 'source' => $row], 'Conditionable callable is inaccessible, unresolved or reached its traversal budget.');
                    }
                }
                foreach ($targets as $target) {
                    if (! $this->ruleBudget($row)) {
                        return;
                    }
                    $this->edge($row['from'], $target, 'validation-rule-builder-callback', $row,
                        ['builder_branch' => $row['metadata']['builder_branch'], 'conditionable_contexts' => $row['metadata']['conditionable_contexts'],
                            'execution_stage' => 'rule-expression-evaluation', 'conditions' => ['The source evaluates this Conditionable rule expression and selects this callback branch; this precedes consumption of its result by a validator.']]);
                }

                continue;
            }
            if ($row['kind'] === 'returned-framework-rule' && isset($owners[$row['from']])) {
                if (! $this->ruleBudget($row)) {
                    return;
                }
                if ($this->frameworkRuleAllowed($row) && $this->standardRule($row, $owners[$row['from']])) {
                    $owners[$row['to']] = $owners[$row['from']];
                }

                continue;
            }
            if (in_array($row['kind'], ['returned-rule-query-callback', 'returned-rule-query-callable'], true) && isset($owners[$row['from']])) {
                if (! $this->ruleBudget($row)) {
                    return;
                }
                $targets = [$row['to']];
                if ($row['kind'] === 'returned-rule-query-callable') {
                    $candidates = $this->calls->sourceCallableCandidates($row['metadata']['callback_receiver'], $row['metadata']['callback_method'], $row['metadata']['callback_exact'], $row['metadata']['factory_call']);
                    $targets = $candidates['targets'];
                    if ($candidates['limited'] || $targets === []) {
                        $this->notice(['id' => $row['from'], 'source' => $row], 'Rule database callable is inaccessible, unresolved or reached its source traversal budget.');
                    }
                }
                foreach ($targets as $target) {
                    if (! $this->ruleBudget($row)) {
                        return;
                    }
                    $this->edge($row['from'], $target, 'validation-rule-query-callback', $row, ['validation_mode' => $owners[$row['from']], 'deferred_rule_contexts' => $row['metadata']['deferred_rule_contexts'] ?? [],
                        'conditions' => ['Default Unique or Exists validation reaches the database presence verifier and applies this query callback; constructing or compiling a validator does not execute it.']]);
                }

                continue;
            }
            if (! in_array($row['kind'], ['returned-rule-condition', 'returned-rule-expander', 'returned-rule-condition-callable'], true) || ! isset($owners[$row['from']]) || ! $this->frameworkRuleAllowed($row)) {
                continue;
            }
            if (! $this->ruleBudget($row)) {
                return;
            }
            $mode = $owners[$row['from']];
            if ($row['kind'] === 'returned-rule-condition-callable') {
                $candidates = $this->calls->sourceCallableCandidates($row['metadata']['callback_receiver'], $row['metadata']['callback_method'], $row['metadata']['callback_exact'], $row['metadata']['factory_call']);
                if ($candidates['limited'] || $candidates['targets'] === []) {
                    $this->notice(['id' => $row['from'], 'source' => $row], 'First-class rule condition is inaccessible, unresolved or reached its source traversal budget.');
                }
                foreach ($candidates['targets'] as $target) {
                    if (! $this->ruleBudget($row)) {
                        return;
                    }
                    $this->edge($row['from'], $target, 'validation-rule-condition', $row, ['validation_mode' => $mode, 'framework_rule_contexts' => $row['metadata']['framework_rule_contexts'], 'deferred_rule_contexts' => $row['metadata']['deferred_rule_contexts'] ?? [],
                        'conditions' => ['The framework compiles this rule condition using the first-class callable created in its source lexical scope; constructing the callable does not execute it.']]);
                }

                continue;
            }
            $kind = $row['kind'] === 'returned-rule-condition' ? 'validation-rule-condition' : 'validation-rule-expander';
            $this->edge($row['from'], $row['to'], $kind, $row, ['validation_mode' => $mode, 'framework_rule_contexts' => $row['metadata']['framework_rule_contexts'], 'deferred_rule_contexts' => $row['metadata']['deferred_rule_contexts'] ?? [],
                'conditions' => [($row['metadata']['deferred_rule_contexts'] ?? []) === []
                    ? 'The validator prepares this candidate branch from the default Rule factory; rule compilation can occur during construction, and conditions and field values are not evaluated.'
                    : 'The outer File or Email rule invokes its nested validator during validation; that validator prepares this candidate branch, and outer construction does not execute it.']]);
            if ($row['kind'] === 'returned-rule-expander') {
                $owners[$row['to']] = $mode;
            }
        }
        foreach ($this->index->relations as $row) {
            if (! in_array($row['kind'], ['returned-rule-candidate', 'returned-rule-callback', 'returned-rule-factory'], true) || ! isset($owners[$row['from']]) || ! $this->frameworkRuleAllowed($row)) {
                continue;
            }
            if (! $this->ruleBudget($row)) {
                return;
            }
            $row['metadata']['validation_mode'] = $owners[$row['from']];
            if ($row['kind'] === 'returned-rule-factory') {
                $returned = $this->calls->factoryReturnCandidates($row['metadata']['return_receiver'], $row['metadata']['factory_call']);
                $fallbackSources = isset($row['metadata']['conditionable_supplier']) ? $this->receiverReturnSources($row['metadata']['conditionable_supplier']) : [];
                $knownFallback = $fallbackSources !== [];
                if ($returned['limited'] || $returned['values'] === [] && ! $knownFallback) {
                    $this->notice(['id' => $row['from'], 'source' => $row], 'Returned validation rule factory is unresolved or reached its source traversal budget.');
                }
                foreach ($returned['values'] as $value) {
                    $origin = $value['sources'][0] ?? null;
                    if ($origin !== null && count(array_filter($fallbackSources, fn ($source) => $source['producer'] === $origin['producer']
                        && $source['path'] === $origin['path'] && $source['offset'] !== null && $source['offset'] === ($origin['offset'] ?? null))) > 0) {
                        continue;
                    }
                    if (str_starts_with($value['receiver'], '@')) {
                        $this->notice(['id' => $row['from'], 'source' => $row], 'Returned validation rule factory result is dynamic or unresolved.');

                        continue;
                    }
                    $targets = $this->index->namedTypes($value['receiver']);
                    if ($targets === []) {
                        $this->notice(['id' => $row['from'], 'source' => $row], 'Returned validation rule factory result declaration is external, absent or ambiguous.');
                    }
                    foreach ($targets as $target) {
                        if (! $this->ruleBudget($row)) {
                            return;
                        }
                        $this->rule([...$row, 'to' => $target, 'metadata' => [...$row['metadata'], 'factory_sources' => $value['sources']]], $links);
                    }
                }

                continue;
            }
            $this->rule($row, $links);
        }
    }

    /** @param array<string, mixed> $row */
    private function standardRule(array $row, string $mode): bool
    {
        $rule = $this->index->elements[$row['to']] ?? null;
        if ($rule === null || $rule['kind'] !== 'framework-validation-rule') {
            return false;
        }
        if ($this->index->namedTypes('Illuminate\\Validation\\Rule') !== [] || $this->index->namedTypes($rule['metadata']['rule_type']) !== []
            || in_array($rule['metadata']['factory_method'], ['unique', 'exists'], true) && $this->index->namedTypes('Illuminate\\Validation\\Rules\\DatabaseRule') !== []) {
            $this->notice(['id' => $row['from'], 'source' => $row], 'Standard rule factory or rule declaration is shadowed in source; default validation behavior is not inferred.');

            return false;
        }
        $versions = $this->frameworkMajors;
        if (in_array($rule['metadata']['factory_method'], CatalogRuleFactories::LARAVEL_13_ONLY, true)) {
            if (in_array(12, $versions, true)) {
                $this->notice(['id' => $row['from'], 'source' => $row], 'This standard Rule factory has no Laravel 12 implementation; macro registration is unresolved.');
            }
            $versions = array_values(array_intersect($versions, [13]));
        }
        if ($versions === []) {
            return false;
        }
        foreach ($row['metadata']['fluent_methods'] ?? [] as $method) {
            if (! in_array($method, CatalogRuleFactories::modifiers($rule['metadata']['factory_method']), true)) {
                $this->notice(['id' => $row['from'], 'source' => $row], 'Standard rule fluent contract is unresolved in the current source snapshot.');

                return false;
            }
            $versions = array_values(array_intersect($versions, CatalogRuleFactories::modifierVersions($rule['metadata']['factory_method'], $method)));
        }
        if ($versions === []) {
            $this->notice(['id' => $row['from'], 'source' => $row], 'Standard rule fluent method is unavailable under the source Laravel version.');

            return false;
        }
        $this->index->elements[$row['to']]['metadata']['framework_versions'] = $versions;
        $this->edge($row['from'], $row['to'], 'validation-rule', $row, ['rule_type' => $rule['metadata']['rule_type'], 'factory_method' => $rule['metadata']['factory_method'],
            'conditionable_contexts' => $row['metadata']['conditionable_contexts'] ?? [],
            'conditionable_fallback_sources' => isset($row['metadata']['conditionable_fallback']) ? $this->receiverReturnSources($row['metadata']['conditionable_fallback']) : [],
            'framework_versions' => $versions, 'fluent_methods' => $row['metadata']['fluent_methods'] ?? [], 'framework_rule_contexts' => $row['metadata']['framework_rule_contexts'] ?? [], 'deferred_rule_contexts' => $row['metadata']['deferred_rule_contexts'] ?? [], 'validation_mode' => $mode,
            'conditions' => [($row['metadata']['deferred_rule_contexts'] ?? []) === []
                ? 'The default validator receives this standard rule under the indicated Laravel version; rule construction does not prove validation, field presence and bail conditions are not evaluated.'
                : 'The outer File or Email rule invokes its nested validator during validation; that validator receives this standard rule, and outer construction does not run it.']]);

        return true;
    }

    /** @param array<string, mixed> $row */
    private function frameworkRuleAllowed(array $row): bool
    {
        if (isset($row['metadata']['conditionable_fallback'])) {
            if (! $this->receiverReturnKnown($row['metadata']['conditionable_fallback'])) {
                return false;
            }
        }
        foreach ($row['metadata']['conditionable_contexts'] ?? [] as $context) {
            $factory = $context['factory'];
            $type = CatalogRuleFactories::type($factory);
            if ($type === null || $this->index->namedTypes('Illuminate\\Validation\\Rule') !== [] || $this->index->namedTypes($type) !== []
                || $factory === 'imagefile' && $this->index->namedTypes('Illuminate\\Validation\\Rules\\File') !== []
                || in_array($factory, ['unique', 'exists'], true) && $this->index->namedTypes('Illuminate\\Validation\\Rules\\DatabaseRule') !== []
                || $this->index->namedTypes('Illuminate\\Support\\Traits\\Conditionable') !== []
                || in_array($factory, CatalogRuleFactories::LARAVEL_13_ONLY, true) && ! in_array(13, $this->frameworkMajors, true)) {
                $this->notice(['id' => $row['from'], 'source' => $row], 'Conditionable rule contract is shadowed or unavailable in this source snapshot.');

                return false;
            }
        }
        if (! isset($row['metadata']['framework_rule_contexts'])) {
            return true;
        }
        if ($this->index->namedTypes('Illuminate\\Validation\\Rule') !== []) {
            $this->notice(['id' => $row['from'], 'source' => $row], 'Rule factory is shadowed by a source declaration; framework branches are not inferred.');

            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $descriptor */
    private function receiverReturnKnown(array $descriptor): bool
    {
        return $this->receiverReturnSources($descriptor) !== [];
    }

    /** @param array<string, mixed> $descriptor
     * @return list<array<string, mixed>>
     */
    private function receiverReturnSources(array $descriptor): array
    {
        $key = hash('xxh128', serialize($descriptor));
        if (isset($this->receiverReturns[$key])) {
            return $this->receiverReturns[$key];
        }
        $candidates = $this->calls->sourceCallableCandidates($descriptor['callback_receiver'], $descriptor['callback_method'], $descriptor['callback_exact'], $descriptor['factory_call']);
        if ($candidates['limited']) {
            return [];
        }
        $sources = [];
        foreach ($candidates['targets'] as $target) {
            foreach ($this->index->out[$target] ?? [] as $position) {
                $return = $this->index->relations[$position];
                if ($return['kind'] === 'returns-null' || $return['kind'] === 'returns-parameter' && $return['metadata']['parameter_index'] === 0) {
                    $sources[] = ['path' => $return['path'], 'line' => $return['line'], 'end_line' => $return['end_line'], 'producer' => $target,
                        'kind' => $return['kind'], 'origin' => $return['metadata']['return_origin'] ?? 'explicit', 'offset' => $return['metadata']['offset'] ?? null];
                }
            }
        }

        return $this->receiverReturns[$key] = $sources;
    }

    /** @param array<string, mixed> $row */
    private function rule(array $row, ExecutionLinks $links): void
    {
        if ($row['kind'] === 'returned-rule-callback') {
            $this->edge($row['from'], $row['to'], 'validation-rule-callback', $row, ['conditionable_contexts' => $row['metadata']['conditionable_contexts'] ?? [], 'framework_rule_contexts' => $row['metadata']['framework_rule_contexts'] ?? [], 'deferred_rule_contexts' => $row['metadata']['deferred_rule_contexts'] ?? [], 'validation_mode' => $row['metadata']['validation_mode'], 'conditions' => ['The validator receives this closure and validation is invoked for the field; construction does not run it, bail and optional-field conditions are not evaluated.']]);

            return;
        }
        $type = $this->index->elements[$row['to']]['name'] ?? null;
        if (! is_string($type)) {
            return;
        }
        if (! $links->inherits($type, 'Illuminate\\Contracts\\Validation\\ValidationRule') && ! $links->inherits($type, 'Illuminate\\Contracts\\Validation\\InvokableRule') && ! $links->inherits($type, 'Illuminate\\Contracts\\Validation\\Rule')) {
            $this->notice(['id' => $row['from'], 'source' => $row], 'Returned rule candidate has no recognized validation contract in source.');

            return;
        }
        $methods = [];
        foreach (['Illuminate\\Contracts\\Validation\\ValidationRule' => ['validate'], 'Illuminate\\Contracts\\Validation\\InvokableRule' => ['__invoke'],
            'Illuminate\\Contracts\\Validation\\Rule' => ['passes', 'message'], 'Illuminate\\Contracts\\Validation\\DataAwareRule' => ['setData'],
            'Illuminate\\Contracts\\Validation\\ValidatorAwareRule' => ['setValidator']] as $contract => $hooks) {
            if ($links->inherits($type, $contract)) {
                array_push($methods, ...$hooks);
            }
        }
        if ($methods === []) {
            $this->notice(['id' => $row['from'], 'source' => $row], 'Returned rule candidate has no recognized validation contract in source.');

            return;
        }
        $this->edge($row['from'], $row['to'], 'validation-rule', $row, ['conditionable_contexts' => $row['metadata']['conditionable_contexts'] ?? [], 'framework_rule_contexts' => $row['metadata']['framework_rule_contexts'] ?? [], 'deferred_rule_contexts' => $row['metadata']['deferred_rule_contexts'] ?? [], 'factory_sources' => $row['metadata']['factory_sources'] ?? [], 'validation_mode' => $row['metadata']['validation_mode'], 'conditions' => ['Default validation receives this rule and validation is invoked; constructing a validator does not run its rules, field presence and bail conditions are not evaluated.']]);
        foreach (array_unique($methods) as $method) {
            $this->hook($type, $row['to'], $method, $method === 'message' ? 'Legacy rule validation fails and the validator obtains its failure message.' : 'The validator reaches this rule under its declared validation contract.', $links, 'validation-rule-handler');
        }
    }

    /** @param array<string, mixed> $row
     * @phpstan-impure
     */
    private function ruleBudget(array $row): bool
    {
        if (++$this->ruleVisits > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
            $this->notice(['id' => $row['from'], 'source' => $row], 'Validation rule composition reached its candidate or memory budget.');

            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $route */
    private function hooks(string $type, string $id, array $route, ExecutionLinks $links): void
    {
        if ($links->method($type, 'validateResolved') !== null) {
            $this->hook($type, $id, 'validateResolved', 'Custom validation replaces the default resolved-request lifecycle.', $links);
            $this->notice($route, 'FormRequest overrides validateResolved; default hooks are not inferred.');

            return;
        }
        foreach (['prepareForValidation', 'passesAuthorization', 'failedAuthorization', 'getValidatorInstance', 'failedValidation', 'passedValidation'] as $method) {
            $condition = match ($method) {
                'failedAuthorization' => 'Authorization denies the request.',
                'failedValidation' => 'Authorization permits the request and the validator fails.',
                'passedValidation' => 'Authorization permits the request and validation succeeds, unless custom failure hooks change control flow.',
                'getValidatorInstance' => 'Authorization permits the request, unless a custom failure hook changes control flow.',
                default => 'Default request lifecycle reaches this stage; authorization and validation outcomes are not evaluated.',
            };
            $this->hook($type, $id, $method, $condition, $links);
        }
        if ($links->method($type, 'passesAuthorization') === null) {
            $this->hook($type, $id, 'authorize', 'Default authorization checks this request before validation.', $links);
        }
        if ($links->method($type, 'getValidatorInstance') !== null) {
            $this->notice($route, 'FormRequest overrides getValidatorInstance; default rule construction is not inferred.');

            return;
        }
        foreach (['withValidator', 'after'] as $method) {
            $this->hook($type, $id, $method, 'Default validator initialization reaches this declared hook.', $links);
        }
        if ($links->method($type, 'validator') !== null) {
            $this->hook($type, $id, 'validator', 'Custom validator replaces default rule construction.', $links);

            return;
        }
        if ($links->method($type, 'createDefaultValidator') !== null) {
            $this->hook($type, $id, 'createDefaultValidator', 'Custom default-validator construction may replace validationRules.', $links);

            return;
        }
        foreach (['validationData', 'messages', 'attributes', 'validationRules'] as $method) {
            $this->hook($type, $id, $method, 'Default validator construction reaches this declaration.', $links);
        }
        if ($links->method($type, 'validationRules') === null) {
            $this->hook($type, $id, 'rules', 'Default validationRules obtains rules from this method.', $links);
        }
    }

    private function hook(string $type, string $id, string $name, string $condition, ExecutionLinks $links, string $kind = 'form-request-hook'): void
    {
        $method = $links->method($type, $name);
        if ($method === null || $method['abstract']) {
            return;
        }
        $targets = $this->index->names[strtolower($method['symbol'])] ?? [];
        if (count($targets) !== 1 || ($this->index->elements[$targets[0]]['metadata']['visibility'] ?? null) === 'private') {
            return;
        }
        if ($kind === 'validation-rule-handler' && ! $method['public']) {
            return;
        }
        $this->edge($id, $targets[0], $kind, $method['source'], ['method' => $name, 'conditions' => [$condition]]);
    }

    /** @param array<string, mixed> $source
     * @param  array<string, mixed>  $metadata
     */
    private function edge(string $from, string $to, string $kind, array $source, array $metadata): void
    {
        $this->index->addRelation(['from' => $from, 'to' => $to, 'kind' => $kind, 'path' => $source['path'], 'line' => $source['line'], 'end_line' => $source['line'],
            'resolution' => 'conditional', 'knowledge' => 'static', 'metadata' => [...$metadata, 'execution_proven' => false]]);
    }

    /** @param array<string, mixed> $route */
    private function notice(array $route, string $message): void
    {
        $this->index->diagnostics[] = ['code' => 'form_request_analysis', 'message' => $message, 'path' => $route['source']['path'], 'line' => $route['source']['line'], 'subject' => $route['id']];
    }
}
