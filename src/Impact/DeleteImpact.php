<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;

/** Simulates declaration removal using query-local facts, without changing source. */
final class DeleteImpact
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $groups = ['breaking' => [], 'check' => [], 'compatible' => []];

    private int $visits = 0;

    private bool $limited = false;

    /** @param array<string, mixed> $subject
     * @param array<string, mixed>|null $declaration
     * @return array<string, mixed> */
    public function inspect(ProjectGraphSnapshot $graph, ImpactIndex $before, array $subject, ?array $declaration, int $limit): array
    {
        $after = new ImpactIndex(new ProjectGraphSnapshot([], []), buildCalls: false);
        $after->classes = $before->classes;
        $removed = [];
        $method = $declaration === null ? null : strtolower($declaration['name']);
        if ($declaration !== null) {
            unset($after->classes[strtolower($declaration['class'])]['methods'][$method]);
            $removed[] = $declaration['symbol'];
        } else {
            foreach ($before->classes as $key => $class) {
                if (($subject['kind'] === 'file' && $class['path'] === $subject['path']) || ($subject['kind'] !== 'file' && strcasecmp($class['name'], $subject['name']) === 0)) {
                    unset($after->classes[$key]);
                    $removed[] = $class['name'];
                }
            }
        }
        $removedKeys = array_map('strtolower', $removed);
        $survives = static function (string $from, string $path) use ($subject, $declaration, $removedKeys): bool {
            if ($declaration !== null) {
                return strcasecmp($from, $declaration['symbol']) !== 0;
            }
            if ($subject['kind'] === 'file' && $path === $subject['path']) {
                return false;
            }

            return ! in_array(strtolower(explode('::', $from, 2)[0]), $removedKeys, true);
        };
        if ($declaration !== null) {
            foreach ($before->signatureCalls($declaration['symbol']) as $call) {
                if (! $this->budget()) {
                    break;
                }
                if (! $survives($call['from'], $call['path'])) {
                    continue;
                }
                $row = ['symbol' => $call['from'], 'path' => $call['path'], 'line' => $call['line'], 'kind' => 'call', 'certainty' => $call['certainty']];
                $receiver = $call['receiver'];
                $fallback = $receiver === null ? null : $after->method($receiver, $declaration['name']);
                if ($call['certainty'] !== 'resolved' || $call['kind'] === 'reference' || $receiver === null || $this->signature($before, $declaration)['abstract'] || ($before->classes[strtolower($declaration['class'])]['kind'] === 'trait') || (($before->classes[strtolower($call['site']['class'])]['kind'] ?? null) === 'trait') || $this->uncertainHierarchy($after, $receiver) || array_filter($call['site']['arguments'], static fn (array $arg): bool => $arg['unpack'])) {
                    $this->add('check', $row, ['Possible dispatch, callable reference, trait scope or incomplete hierarchy requires inspection after removal.']);

                    continue;
                }
                $handler = $call['site']['form'] === 'instance' ? '__call' : '__callStatic';
                if ($after->method($receiver, $handler) !== null) {
                    $this->add('check', $row, ['Magic dispatch on the receiver may handle the removed or inaccessible method.']);
                } elseif ($fallback === null) {
                    $this->add($method === '__construct' ? 'check' : 'breaking', $row, [$method === '__construct' ? 'No explicit constructor remains; inspect implicit construction and initialization.' : 'method_missing_after_delete']);
                } else {
                    $oldSignature = $this->signature($before, $declaration);
                    $newSignature = $this->signature($after, $fallback);
                    $accessDeclaration = $this->accessDeclaration($after, $fallback, $receiver);
                    if ($accessDeclaration === null) {
                        $this->add('check', $row, ['The class importing the trait method cannot be resolved; inspect its access scope.']);

                        continue;
                    }
                    $errors = array_values(array_diff($this->callErrors($after, $newSignature, $call['site'], $accessDeclaration), $this->callErrors($before, $oldSignature, $call['site'], $declaration)));
                    $reasons = SignatureCompatibility::semanticChanges($oldSignature, $newSignature);
                    $reasons[] = 'Removal changes the method body reached by this call to '.$fallback['symbol'].'.';
                    $this->add($errors !== [] ? 'breaking' : 'check', [...$row, 'fallback' => $fallback['symbol']], $errors !== [] ? $errors : $reasons);
                }
            }
        } else {
            foreach ($graph->edges as $edge) {
                if (! $this->budget()) {
                    break;
                }
                if (! in_array(strtolower($edge->to), $removedKeys, true) || ! $survives($edge->from, $edge->path)) {
                    continue;
                }
                $proved = in_array($edge->kind, ['new', 'static', 'extends', 'implements', 'trait'], true);
                $this->add($proved ? 'breaking' : 'check', ['symbol' => $edge->from, 'path' => $edge->path, 'line' => $edge->line, 'kind' => $edge->kind, 'certainty' => 'resolved'], [$proved ? 'declaration_missing_after_delete:'.$edge->to : 'This reference alone does not prove a PHP error; inspect types, imports and registrations using '.$edge->to.'.']);
            }
            // Method facts include file-scope calls absent from the classic class graph.
            foreach ($before->calls as $call) {
                if (! $this->budget()) {
                    break;
                }
                $receiver = $before->receiver($call['receiver']);
                if ($receiver === null || ! in_array(strtolower($receiver), $removedKeys, true) || ! $survives($call['from'], $call['path'])) {
                    continue;
                }
                $proved = $call['kind'] !== 'reference' && in_array($call['site']['form'], ['new', 'class'], true) && $call['exact'];
                $this->add($proved ? 'breaking' : 'check', ['symbol' => $call['from'], 'path' => $call['path'], 'line' => $call['line'], 'kind' => $call['kind'], 'certainty' => $proved ? 'resolved' : 'possible'], [$proved ? 'declaration_missing_after_delete:'.$receiver : 'Inspect the receiver or callable reference after declaration removal.']);
            }
        }
        if ($declaration !== null) {
            $this->contracts($before, $after, $method);
        }
        $this->add('check', ['symbol' => $declaration['symbol'] ?? $subject['name'], 'path' => $subject['path'], 'line' => $declaration['line'] ?? $subject['line'], 'kind' => 'removal', 'certainty' => 'possible'], ['Inspect framework/container registrations, external callers and initialization. Removing code is not proved safe by this report.']);
        $totals = array_map('count', $this->groups);
        $truncated = $this->limited || $before->limitReached() || $after->limitReached() || max($totals) > $limit;

        return ['mode' => 'delete', 'removed' => $removed, ...array_map(static fn (array $rows): array => array_slice($rows, 0, $limit), $this->groups), 'total' => $totals, 'truncated' => $truncated, 'safe_to_change' => false,
            'status' => $truncated ? 'limit' : ($totals['breaking'] > 0 ? 'breaking' : 'check'),
            'limitations' => ['BREAKING is a proved static incompatibility if the remaining code reaches the selected declaration; it is not proof of runtime execution.', 'Dynamic names, aliases, magic calls, framework dispatch and registrations require inspection. File-level functions, includes and side effects are not fully modelled.', 'Removing an inherited selector removes its actual declaration. A file removal removes all indexed declarations and file-scope code in that file.', 'No breaking rows proves neither safety nor test PASS. Totals count evaluated rows and are lower bounds when truncated. Analysis is bounded to 10000 visits and memory headroom.']];
    }

    /** @param array<string, mixed> $signature
     * @param array<string, mixed> $site
     * @param array<string, mixed> $declaration
     * @return list<string> */
    private function callErrors(ImpactIndex $index, array $signature, array $site, array $declaration): array
    {
        $errors = SignatureCompatibility::argumentErrors($signature, $site);
        $caller = $site['class'];
        $related = strcasecmp($caller, $declaration['class']) === 0 || in_array(strtolower($declaration['class']), array_map('strtolower', $index->ancestors($caller)), true);
        if (! $signature['static'] && in_array($site['form'], ['class', 'self', 'parent', 'static'], true) && ($site['static_context'] || ! $related)) {
            $errors[] = 'non_static_method_called_statically';
        }
        if ($signature['visibility'] === 'private' && strcasecmp($caller, $declaration['class']) !== 0) {
            $errors[] = 'private_method_outside_declaring_class';
        }
        if ($signature['visibility'] === 'protected' && ! $related && ! in_array(strtolower($caller), array_map('strtolower', $index->ancestors($declaration['class'])), true)) {
            $errors[] = 'protected_method_outside_class_hierarchy';
        }

        return $errors;
    }

    /** @param array<string, mixed> $declaration
     * @return array<string, mixed> */
    private function signature(ImpactIndex $index, array $declaration): array
    {
        return $index->classes[strtolower($declaration['class'])]['methods'][strtolower($declaration['name'])]['signature'];
    }

    /** @param array<string, mixed> $declaration
     * @return array<string, mixed>|null */
    private function accessDeclaration(ImpactIndex $index, array $declaration, string $receiver): ?array
    {
        if ($index->classes[strtolower($declaration['class'])]['kind'] !== 'trait') {
            return $declaration;
        }
        foreach ([$receiver, ...$index->ancestors($receiver)] as $name) {
            $class = $index->classes[strtolower($name)] ?? null;
            if ($class === null || $class['kind'] !== 'class') {
                continue;
            }
            foreach ($class['traits'] as $trait) {
                $imported = $index->method($trait, $declaration['name']);
                if ($imported !== null && strcasecmp($imported['symbol'], $declaration['symbol']) === 0) {
                    return [...$declaration, 'class' => $class['name']];
                }
            }
        }

        return null;
    }

    private function uncertainHierarchy(ImpactIndex $index, string $receiver): bool
    {
        foreach ([$receiver, ...$index->ancestors($receiver)] as $name) {
            if (! isset($index->classes[strtolower($name)]) || $index->classes[strtolower($name)]['adaptations']) {
                return true;
            }
        }

        return false;
    }

    private function contracts(ImpactIndex $before, ImpactIndex $after, string $method): void
    {
        foreach ($after->classes as $class) {
            if (! $this->budget()) {
                break;
            }
            if ($class['kind'] !== 'class') {
                continue;
            }
            $oldMethod = $before->method($class['name'], $method);
            $newMethod = $after->method($class['name'], $method);
            foreach ($after->ancestors($class['name']) as $name) {
                if (! $this->budget()) {
                    break 2;
                }
                $ancestor = $after->classes[strtolower($name)] ?? null;
                $requirement = $ancestor['methods'][$method]['signature'] ?? null;
                if ($requirement === null) {
                    continue;
                }
                $traitRequirement = $ancestor['kind'] === 'trait' && $requirement['abstract'];
                if (! $traitRequirement && $ancestor['kind'] !== 'interface' && ! $requirement['abstract']) {
                    continue;
                }
                if ($oldMethod === null || $this->signature($before, $oldMethod)['abstract']) {
                    continue;
                }
                $row = ['symbol' => $class['name'].'::'.$method, 'path' => $class['path'], 'line' => $class['line'], 'kind' => 'contract', 'certainty' => 'resolved'];
                if ($newMethod === null || $this->signature($after, $newMethod)['abstract']) {
                    $this->add($class['abstract'] ? 'check' : 'breaking', $row, ['required_implementation_missing_after_delete:'.$name.'::'.$method]);
                } else {
                    $old = SignatureCompatibility::contract($requirement, $this->signature($before, $oldMethod), $traitRequirement);
                    $new = SignatureCompatibility::contract($requirement, $this->signature($after, $newMethod), $traitRequirement);
                    $errors = array_values(array_diff($new['errors'], $old['errors']));
                    if ($errors !== []) {
                        $this->add('breaking', $row, $errors);
                    } elseif ($oldMethod['symbol'] !== $newMethod['symbol']) {
                        $this->add('check', $row, ['Implementation changed to '.$newMethod['symbol'].'; inspect contract variance and behaviour.']);
                    }
                }
            }
        }
    }

    private function budget(): bool
    {
        $memory = MemoryLimit::bytes();
        if (++$this->visits > 10000 || ($memory !== null && memory_get_usage(true) + 65536 > $memory * 0.8)) {
            $this->limited = true;

            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $row
     * @param list<string> $reasons */
    private function add(string $group, array $row, array $reasons): void
    {
        $this->groups[$group][] = [...$row, 'reasons' => $reasons];
    }
}
