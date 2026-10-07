<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteEntry;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Audit\TestReachability\HttpTestResolver;
use GracjanKubicki\ArchitectureKit\Audit\TestReachability\TestInvocation;

/** Static test candidates are never test execution, PASS or proof of complete coverage. */
final class CatalogTestResolver
{
    public function __construct(private readonly CatalogIndex $index) {}

    public function resolve(): void
    {
        foreach ($this->index->relations as &$edge) {
            if ($edge['kind'] === 'test-body') {
                $declaration = $this->index->elements[$edge['from']];
                $body = $this->index->elements[$edge['to']] ?? null;
                if ($declaration['kind'] === 'test-hook' && ! ($body['metadata']['return_sites']['complete'] ?? false)) {
                    $edge['kind'] = 'references-test-body';
                    $this->diagnostic($declaration, 'Test hook callback is a source generator or its body analysis is incomplete.');
                } elseif (! $this->validApi($declaration)) {
                    $edge['kind'] = 'references-test-body';
                    $this->diagnostic($declaration, 'Source function shadows the test registration API.');
                }
            }
        }
        unset($edge);
        $entries = [];
        foreach ($this->index->elements as &$element) {
            if (isset($element['metadata']['test_api'])) {
                $element['metadata']['test_registration_resolved'] = $this->validApi($element);
            }
            if (($element['metadata']['test_declaration_candidate'] ?? false) && $this->verifiedOwner($element)) {
                $element['roles'][] = 'test-method';
                $element['role_evidence'][] = ['role' => 'test-method', 'basis' => 'framework-test-declaration', 'path' => $element['path'], 'line' => $element['line'], 'contract' => 'PHPUnit\\Framework\\TestCase'];
                $element['metadata']['test_executed'] = false;
            }
            if ($element['kind'] === 'route' && is_string($element['metadata']['uri'] ?? null) && is_array($element['metadata']['verbs'] ?? null)
                && is_array($element['metadata']['constraints'] ?? null)) {
                $meta = $element['metadata'];
                $entries[] = new RouteEntry($meta['verbs'], $meta['uri'], $meta['route_name'] ?? null, $meta['domain'] ?? null, $meta['constraints'], $element['id'], '(source)');
            }
        }
        unset($element);
        $hookNames = [];
        foreach ($this->index->elements as $method) {
            if ($method['kind'] === 'method' && ($method['metadata']['phpunit_hooks'] ?? []) !== []) {
                $hookNames[strtolower(substr($method['name'], strrpos($method['name'], '::') + 2))] = true;
            }
        }
        $attributeHooks = [];
        $sourceMethods = new CatalogCallResolver($this->index);
        $resolver = new HttpTestResolver(new RouteMap(entries: $entries));
        foreach ($this->index->elements as $element) {
            if ($element['kind'] === 'pest-test' && $this->validApi($element)) {
                foreach ($element['metadata']['datasets'] ?? [] as $name) {
                    $matches = array_filter($this->index->names[strtolower($name)] ?? [], fn ($id) => $this->index->elements[$id]['kind'] === 'dataset' && $this->validApi($this->index->elements[$id]));
                    foreach ($matches as $id) {
                        $this->relation($element, $id, 'references-test-dataset', ['setup_only' => true, 'candidate_count' => count($matches)]);
                    }
                    if ($matches === []) {
                        $this->diagnostic($element, 'Named dataset declaration is absent from the source catalog.');
                    }
                }
                foreach ($this->index->elements as $hook) {
                    if ($hook['kind'] === 'test-hook' && $this->validApi($hook) && $this->hookApplies($hook, $element)) {
                        $this->relation($element, $hook['id'], str_starts_with($hook['metadata']['test_api'], 'after') ? 'test-teardown' : 'test-setup', ['conditions' => ['The registered test hook must apply to this test.']]);
                    }
                }
            }
            if (in_array('test-method', $element['roles'], true)) {
                $parent = $this->index->elements[$element['parent'] ?? ''] ?? null;
                if ($parent !== null) {
                    foreach ($element['metadata']['phpunit_providers'] ?? [] as $provider) {
                        $contract = 'PHPUnit\\Framework\\Attributes\\'.$provider['api'];
                        $selected = $sourceMethods->sourceMethodCandidate($provider['class'] ?? $parent['name'], $provider['method']);
                        $method = $selected['method'];
                        if ($selected['limited']) {
                            $this->diagnostic($element, 'PHPUnit data provider selection reached its source budget.', 'catalog_limit');
                        }
                        if ($this->index->namedTypes($contract) !== [] || $method === null || $selected['limited'] || $selected['unresolved']
                            || ($method['metadata']['visibility'] ?? null) !== 'public' || ! ($method['metadata']['static'] ?? false)
                            || ($method['metadata']['abstract'] ?? false) || ! ($method['metadata']['call_parameters']['complete'] ?? false)
                            || ($method['metadata']['call_parameters']['parameters'] ?? []) !== []) {
                            $this->diagnostic($element, 'PHPUnit data provider cannot be selected from a valid source declaration.');

                            continue;
                        }
                        $this->relation($element, $method['id'], 'test-dataset-provider', ['setup_only' => true, 'provider_attribute' => $contract,
                            'validate_argument_count' => $provider['validate_argument_count'], 'dataset_values_retained' => false,
                            'conditions' => ['PHPUnit must select this source provider and its return value must be valid iterable test data.']]);
                    }
                    if (! isset($attributeHooks[$parent['id']])) {
                        $attributeHooks[$parent['id']] = [];
                        foreach (array_slice(array_keys($hookNames), 0, 256) as $name) {
                            $selected = $sourceMethods->sourceMethodCandidate($parent['name'], $name);
                            if ($selected['limited']) {
                                $this->diagnostic($element, 'PHPUnit attribute hook selection reached its source budget.', 'catalog_limit');
                            }
                            if ($selected['method'] !== null && ! $selected['limited'] && ! $selected['unresolved']) {
                                $attributeHooks[$parent['id']][] = $selected['method'];
                            }
                        }
                        if (count($hookNames) > 256) {
                            $this->diagnostic($element, 'PHPUnit attribute hook names exceeded the source selection budget.', 'catalog_limit');
                        }
                    }
                    foreach ($attributeHooks[$parent['id']] as $method) {
                        $metadata = $method['metadata'];
                        foreach ($metadata['phpunit_hooks'] ?? [] as $hook) {
                            $contract = 'PHPUnit\\Framework\\Attributes\\'.$hook['api'];
                            if ($this->index->namedTypes($contract) !== [] || ($metadata['abstract'] ?? false)
                                || ! in_array($metadata['visibility'] ?? null, ['public', 'protected'], true)
                                || str_ends_with($hook['api'], 'Class') && ! ($metadata['static'] ?? false)
                                || ! ($metadata['call_parameters']['complete'] ?? false)
                                || array_filter($metadata['call_parameters']['parameters'] ?? [], fn ($parameter) => $parameter['required']) !== []) {
                                $this->diagnostic($element, 'PHPUnit attribute hook has an incompatible or source-shadowed declaration.');

                                continue;
                            }
                            $kind = match ($hook['api']) {
                                'Before', 'BeforeClass' => 'test-setup', 'After', 'AfterClass' => 'test-teardown',
                                'PreCondition' => 'test-precondition', default => 'test-postcondition',
                            };
                            $this->relation($element, $method['id'], $kind, ['hook_attribute' => $contract, 'priority' => $hook['priority'],
                                'priority_resolved' => $hook['priority'] !== null, 'conditions' => ['PHPUnit must select this attributed source method in its test lifecycle.']]);
                        }
                    }
                    foreach (['setUp' => false, 'setUpBeforeClass' => true, 'tearDown' => false, 'tearDownAfterClass' => true] as $hook => $static) {
                        $selected = $sourceMethods->sourceMethodCandidate($parent['name'], $hook);
                        $method = $selected['method'];
                        if ($selected['limited']) {
                            $this->diagnostic($element, 'PHPUnit hook selection reached its source budget.', 'catalog_limit');
                        }
                        if ($method === null || $selected['unresolved'] || $selected['limited']) {
                            continue;
                        }
                        $metadata = $method['metadata'];
                        if (! in_array($metadata['visibility'] ?? null, ['public', 'protected'], true)
                            || ($metadata['static'] ?? false) !== $static || ($metadata['abstract'] ?? false)
                            || ! ($metadata['call_parameters']['complete'] ?? false)
                            || array_filter($metadata['call_parameters']['parameters'] ?? [], fn ($parameter) => $parameter['required']) !== []) {
                            $this->diagnostic($element, 'PHPUnit source hook is inaccessible or has an incompatible declaration.');

                            continue;
                        }
                        $this->relation($element, $method['id'], str_starts_with($hook, 'tearDown') ? 'test-teardown' : 'test-setup',
                            ['hook' => $hook, 'conditions' => ['The PHPUnit test lifecycle must select this source hook.']]);
                    }
                }
            }
            if ($element['kind'] !== 'test-invocation') {
                continue;
            }
            $owner = $this->index->elements[$element['parent'] ?? ''] ?? null;
            if ($owner === null || ! $this->verifiedOwner($owner)) {
                $this->diagnostic($element, 'Test invocation owner is not a verified source test body.');

                continue;
            }
            $call = TestInvocation::fromArray($element['path'], $element['metadata']['test_invocation']);
            if ($call->kind === 'factory') {
                $ids = $call->model === null ? [] : $this->index->namedTypes($call->model);
                if (count($ids) === 1 && $this->index->hasContract($ids[0], 'Illuminate\\Database\\Eloquent\\Model') && $this->index->namedTypes('Illuminate\\Database\\Eloquent\\Model') === []) {
                    $this->relation($owner, $ids[0], 'references-factory-setup', ['setup_only' => true], $element);
                } else {
                    $this->diagnostic($element, 'Factory setup model is absent, ambiguous or has no verified Eloquent contract.');
                }

                continue;
            }
            try {
                $matches = $resolver->sourceCandidates($call);
            } catch (\Throwable) {
                $matches = 'Source route pattern could not be compiled safely.';
            }
            if (is_string($matches)) {
                $this->diagnostic($element, $matches);

                continue;
            }
            foreach ($matches as $entry) {
                $this->relation($owner, $entry->class, 'test-http-candidate', ['runtime_dispatch_known' => false, 'candidate_count' => count($matches), 'conditions' => ['Source route registration must be active and request dispatch must reach this route.']], $element);
            }
        }
    }

    /** @param array<string, mixed> $element */
    private function verifiedOwner(array $element): bool
    {
        if ($element['kind'] === 'method') {
            $parent = $element['parent'];

            return is_string($parent) && $this->index->hasContract($parent, 'PHPUnit\\Framework\\TestCase') && $this->index->namedTypes('PHPUnit\\Framework\\TestCase') === [];
        }
        if ($element['kind'] === 'closure') {
            foreach ($this->index->in[$element['id']] ?? [] as $position) {
                $edge = $this->index->relations[$position];
                if ($edge['kind'] === 'test-body') {
                    $owner = $this->index->elements[$edge['from']];

                    return $this->validApi($owner);
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $element */
    private function validApi(array $element): bool
    {
        $api = $element['metadata']['registration_api'] ?? $element['metadata']['test_api'] ?? null;
        if (isset($element['metadata']['test_targets']) && (! $element['metadata']['targets_resolved']
            || $this->index->namedTypes('Pest\\Configuration') !== [] || $this->index->namedTypes('Pest\\PendingCalls\\UsesCall') !== [])) {
            return false;
        }
        if (! is_string($api)) {
            return false;
        }

        return array_filter($this->index->names[$api] ?? [], fn ($id) => $this->index->elements[$id]['kind'] === 'function') === [];
    }

    /** @param array<string, mixed> $hook
     * @param  array<string, mixed>  $test
     */
    private function hookApplies(array $hook, array $test): bool
    {
        if (! isset($hook['metadata']['test_targets'])) {
            return $hook['path'] === $test['path'] && $hook['metadata']['registration_scope'] === $test['metadata']['registration_scope'];
        }
        $path = $test['path'];
        foreach ($hook['metadata']['test_targets'] as $target) {
            $candidate = $path;
            for ($depth = 0; $depth < 128 && $candidate !== '.'; $depth++) {
                if (fnmatch($target, $candidate, FNM_PATHNAME)) {
                    return true;
                }
                $candidate = dirname($candidate);
            }
        }

        return false;
    }

    /** @param array<string, mixed> $owner
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>|null  $site
     */
    private function relation(array $owner, string $to, string $kind, array $metadata, ?array $site = null): void
    {
        $site ??= $owner;
        $this->index->addRelation(['from' => $owner['id'], 'to' => $to, 'kind' => $kind, 'path' => $site['path'], 'line' => $site['line'], 'end_line' => $site['end_line'],
            'resolution' => 'conditional', 'knowledge' => 'static', 'metadata' => [...$metadata, 'execution_proven' => false, 'test_executed' => false]]);
    }

    /** @param array<string, mixed> $element */
    private function diagnostic(array $element, string $message, string $code = 'test_analysis'): void
    {
        $this->index->diagnostics[] = ['code' => $code, 'message' => $message, 'path' => $element['path'], 'line' => $element['line'], 'subject' => $element['id']];
    }
}
