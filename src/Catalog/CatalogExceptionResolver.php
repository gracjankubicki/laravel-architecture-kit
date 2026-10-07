<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ExecutionLinks;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** Registers possible exception callbacks without assuming that an exception was thrown. */
final class CatalogExceptionResolver
{
    public function __construct(private readonly CatalogIndex $index) {}

    /** @param array<string, mixed> $facts */
    public function resolve(array $facts, ExecutionLinks $links): void
    {
        $active = [];
        $visits = 0;
        foreach ($facts['operations'] as $op) {
            if ($op['kind'] !== 'exception_activation' || str_starts_with($op['from'], '(callback)') && ! isset($active[$op['from']])) {
                continue;
            }
            $callback = $op['args']['callback'] ?? null;
            if (($callback['type'] ?? null) === 'callback') {
                $active[$callback['symbol']] = $op['conditions'];
            } elseif (($callback['type'] ?? null) === 'reference') {
                $names = $callback['function_names'] ?? [$callback['symbol']];
                $activated = false;
                foreach ($names as $name) {
                    $targets = array_values(array_filter($this->index->names[strtolower($name)] ?? [], fn ($id) => in_array($this->index->elements[$id]['kind'], ['method', 'function'], true)));
                    if (count($targets) === 1) {
                        $element = $this->index->elements[$targets[0]];
                        $form = $callback['callable_form'] ?? null;
                        if (($element['metadata']['abstract'] ?? false) || $element['kind'] === 'method' && (
                            $form !== null && ($element['metadata']['visibility'] ?? null) !== 'public'
                            || in_array($form, ['static-array', 'static-string'], true) && ! ($element['metadata']['static'] ?? false))) {
                            break;
                        }
                        $activated = true;
                        $active[$this->index->elements[$targets[0]]['name']] = $op['conditions'];
                        break;
                    }
                }
                if (! $activated) {
                    $this->notice($op, 'withExceptions activation callable is absent, ambiguous or invalid for its declared form.');
                }
            } else {
                $this->notice($op, 'withExceptions activation callback is unresolved.');
            }
        }
        $controls = $this->controls($facts, $links, $active);
        $this->declarations($facts, $links, $controls);
        $stops = [];
        foreach ($facts['operations'] as $option) {
            if ($option['kind'] === 'exception_options' && $option['method'] === 'stop' && is_array($option['registration_site'] ?? null)) {
                $key = $option['registration_site']['path'].':'.$option['registration_site']['offset'];
                $stops[$key][] = ['source' => $option['source'], 'conditions' => $option['conditions']];
            }
        }
        foreach ($facts['operations'] as $op) {
            $filter = $op['kind'] === 'exception_control' && $op['method'] === 'dontreportwhen';
            if ($op['kind'] !== 'exception_registration' && ! $filter) {
                continue;
            }
            if (++$visits > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
                $this->notice($op, 'Exception callback composition reached its operation or memory budget.');

                return;
            }
            $modern = $op['owner'] === 'Illuminate\Foundation\Configuration\Exceptions';
            $legacy = $links->inherits($op['owner'], 'Illuminate\Foundation\Exceptions\Handler') && in_array($op['method'], ['reportable', 'renderable', 'dontreportwhen'], true)
                && $links->method($op['owner'], $op['method']) === null;
            if (! $modern && ! $legacy) {
                continue;
            }
            if ($modern && ! isset($active[$op['from']])) {
                $this->notice($op, 'Exception callback has no recognized withExceptions activation.');

                continue;
            }
            $callback = $op['args']['callback'];
            $symbol = $callback['symbol'] ?? null;
            if (is_string($symbol) && str_starts_with($symbol, '(callback) ')) {
                $symbol = '(closure) '.substr($symbol, 11);
            }
            $targets = is_string($symbol) ? ($this->index->names[strtolower($symbol)] ?? []) : [];
            foreach ($callback['function_names'] ?? [] as $name) {
                $functions = array_values(array_filter($this->index->names[strtolower($name)] ?? [], fn ($id) => $this->index->elements[$id]['kind'] === 'function'));
                if ($functions !== []) {
                    $targets = $functions;
                    break;
                }
            }
            if ($targets === [] && is_string($symbol) && str_contains($symbol, '::')) {
                [$owner, $method] = explode('::', $symbol, 2);
                $declaration = $links->method($owner, $method);
                if ($declaration !== null && $declaration['public'] && ! $declaration['abstract']) {
                    $targets = $this->index->names[strtolower($declaration['symbol'])] ?? [];
                }
            }
            $targets = array_values(array_filter($targets, fn ($id) => in_array($this->index->elements[$id]['kind'], ['method', 'function', 'closure'], true)));
            $types = $callback['events'] ?? [];
            if (($callback['type'] ?? null) === 'reference' && count($targets) === 1) {
                $metadata = $this->index->elements[$targets[0]]['metadata'];
                $form = $callback['callable_form'] ?? null;
                if (($metadata['abstract'] ?? false) || ($form !== null && $this->index->elements[$targets[0]]['kind'] === 'method' && ($metadata['visibility'] ?? null) !== 'public')
                    || (in_array($form, ['static-array', 'static-string'], true) && ! ($metadata['static'] ?? false))) {
                    $this->notice($op, 'Exception callable has no callable public source method for the declared form.');

                    continue;
                }
                $types = $metadata['parameters'][0]['types'] ?? [];
            }
            if (count($targets) !== 1 || $types === []) {
                $this->notice($op, 'Exception callback or its first parameter type is unresolved.');

                continue;
            }
            foreach ($types as $type) {
                $ids = $this->index->namedTypes($type);
                if (count($ids) > 1) {
                    $this->notice($op, 'Exception type declaration is ambiguous.');

                    continue;
                }
                $contract = $this->exceptionContract($type);
                if ($contract === false) {
                    $this->notice($op, 'Callback parameter has no recognized exception contract in source.');

                    continue;
                }
                if ($contract === null) {
                    $this->notice($op, 'Callback exception contract depends on a type outside the declared source graph.');
                }
                $id = $ids[0] ?? CatalogElement::resourceIdentity('exception-type', $type);
                if (! isset($this->index->elements[$id])) {
                    $element = new CatalogElement($id, $type, 'exception-type', $op['source']['line'], $op['source']['line'], $op['source']['offset'], metadata: ['external' => true, 'exception_contract_known' => $contract === true]);
                    $this->index->elements[$id] = [...$element->toArray(), 'path' => $op['source']['path'], 'knowledge' => 'static', 'role_evidence' => [], 'sources' => [$op['source']]];
                    $this->index->names[strtolower($type)][] = $id;
                }
                $mode = $filter ? 'filter' : (str_starts_with($op['method'], 'report') ? 'report' : 'render');
                $this->index->addRelation(['from' => $id, 'to' => $targets[0], 'kind' => 'exception-'.$mode.'-registration',
                    'path' => $op['source']['path'], 'line' => $op['source']['line'], 'end_line' => $op['source']['line'], 'resolution' => 'conditional', 'knowledge' => 'static',
                    'metadata' => ['exception_type' => $type, 'registration' => $op['source'], 'execution_proven' => false, 'exception_contract_known' => $contract === true,
                        ...($mode === 'report' ? ['shouldnt_report_contract' => $this->index->hasContract($id, 'Illuminate\\Contracts\\Debug\\ShouldntReport')] : []),
                        ...($mode === 'report' ? ['stop_after_callback' => $stops[$op['source']['path'].':'.$op['source']['offset']] ?? [], 'callback_returns_false_candidate' => $op['callback_returns_false_candidate'] ?? false, 'reporting_controls' => $controls] : []),
                        'conditions' => [...($active[$op['from']] ?? []), ...$op['conditions'], $filter ? 'The default reporting pipeline must reach this predicate; a true result suppresses reporting, and the predicate is not evaluated.' : 'Handler registration must be active and receive a matching exception; ordering, suppression and callback results are not evaluated.']]]);
            }
        }
    }

    /** @param array<string, mixed> $facts
     * @param  list<array<string, mixed>>  $controls
     */
    private function declarations(array $facts, ExecutionLinks $links, array $controls): void
    {
        $visits = 0;
        foreach ($facts['classes'] as $class) {
            if (++$visits > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
                $this->notice(['source' => ['path' => $class['path'], 'line' => $class['line']]], 'Exception declaration composition reached its operation or memory budget.');

                return;
            }
            if ($class['ambiguous'] ?? false) {
                continue;
            }
            $handler = $links->inherits($class['name'], 'Illuminate\\Foundation\\Exceptions\\Handler');
            $exception = $links->inherits($class['name'], 'Throwable') || $links->inherits($class['name'], 'Exception') || $links->inherits($class['name'], 'Error');
            if (! $handler && ! $exception) {
                continue;
            }
            $owners = $this->index->namedTypes($class['name']);
            if (count($owners) !== 1) {
                continue;
            }
            $methods = $handler ? ['report', 'reportThrowable', 'render'] : ['report', 'render'];
            if ($exception && $links->inherits($class['name'], 'Illuminate\\Contracts\\Support\\Responsable')) {
                $methods[] = 'toResponse';
            }
            foreach ($methods as $name) {
                $method = $links->method($class['name'], $name);
                if ($method === null || $method['abstract'] || (! $method['public'] && $name !== 'reportThrowable')) {
                    continue;
                }
                $targets = $this->index->names[strtolower($method['symbol'])] ?? [];
                if (count($targets) !== 1) {
                    continue;
                }
                if (($this->index->elements[$targets[0]]['metadata']['visibility'] ?? null) === 'private') {
                    continue;
                }
                $mode = str_starts_with($name, 'report') ? 'report' : 'render';
                $source = $method['source'];
                $this->index->addRelation(['from' => $owners[0], 'to' => $targets[0], 'kind' => 'exception-'.$mode.'-handler',
                    'path' => $source['path'], 'line' => $source['line'], 'end_line' => $source['line'], 'resolution' => 'conditional', 'knowledge' => 'static',
                    'metadata' => ['method' => $name, 'handler_candidate' => $handler, 'execution_proven' => false,
                        ...($mode === 'report' ? ['reporting_controls' => $controls, 'shouldnt_report_contract' => $this->index->hasContract($owners[0], 'Illuminate\\Contracts\\Debug\\ShouldntReport')] : []),
                        'conditions' => [$handler
                            ? 'This source Handler must be selected and receive an exception; custom overrides may replace the default pipeline.'
                            : 'The exception must reach the default Handler pipeline; suppression, callback ordering and return values may prevent later handlers.']]]);
            }
        }
    }

    /** Fixed PHP contracts are known without reflection or consumer autoloading.
     * @param  array<string, bool>  $seen
     */
    private function exceptionContract(string $type, array $seen = []): ?bool
    {
        if (in_array(strtolower($type), ['throwable', 'exception', 'error', 'errorexception', 'runtimeexception', 'logicexception', 'invalidargumentexception', 'badfunctioncallexception', 'badmethodcallexception', 'domainexception', 'lengthexception', 'outofrangeexception', 'outofboundsexception', 'overflowexception', 'underflowexception', 'unexpectedvalueexception', 'typeerror', 'argumentcounterror', 'valueerror', 'arithmeticerror', 'divisionbyzeroerror', 'parseerror', 'asserterror', 'unhandledmatcherror'], true)) {
            return true;
        }
        $ids = $this->index->namedTypes($type);
        if (count($ids) !== 1 || isset($seen[$ids[0]]) || count($seen) >= 32) {
            return null;
        }
        $seen[$ids[0]] = true;
        $unknown = false;
        foreach ($this->index->out[$ids[0]] ?? [] as $position) {
            $edge = $this->index->relations[$position];
            if (! in_array($edge['kind'], ['extends', 'implements'], true)) {
                continue;
            }
            $name = $this->index->elements[$edge['to']]['name'] ?? $edge['metadata']['target_name'] ?? (str_starts_with($edge['to'], 'php:') ? substr($edge['to'], 4) : null);
            $result = is_string($name) ? $this->exceptionContract($name, $seen) : null;
            if ($result === true) {
                return true;
            }
            $unknown = $unknown || $result === null;
        }

        return $unknown ? null : false;
    }

    /** @param array<string, mixed> $facts
     * @param  array<string, list<string>>  $active
     * @return list<array<string, mixed>>
     */
    private function controls(array $facts, ExecutionLinks $links, array $active): array
    {
        $controls = [];
        foreach ($facts['operations'] as $op) {
            if ($op['kind'] !== 'exception_control') {
                continue;
            }
            $modern = $op['owner'] === 'Illuminate\Foundation\Configuration\Exceptions' && isset($active[$op['from']]);
            $legacy = $links->inherits($op['owner'], 'Illuminate\Foundation\Exceptions\Handler') && $links->method($op['owner'], $op['method']) === null;
            if (! $modern && ! $legacy) {
                if ($op['owner'] === 'Illuminate\Foundation\Configuration\Exceptions') {
                    $this->notice($op, 'Exception reporting control has no recognized active framework registration.');
                }

                continue;
            }
            if (count($controls) >= 10000 || ImpactExtractor::sourceLimit(0) !== null) {
                $this->notice($op, 'Exception reporting controls reached their count or memory budget.');
                break;
            }
            $classes = $op['args']['classes'] ?? null;
            $classes = is_string($classes) ? [$classes] : (is_array($classes) && array_is_list($classes) ? $classes : []);
            $valid = array_values(array_filter($classes, fn ($type) => is_string($type) && preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*$/D', $type)));
            $callback = $op['args']['callback'] ?? null;
            if ($op['method'] === 'dontreportwhen' && $callback === null) {
                $this->notice($op, 'Exception reporting predicate callback is unresolved.');
            }
            if (! in_array($op['method'], ['dontreportwhen', 'dontreportduplicates'], true) && ($valid === [] || count($valid) !== count($classes))) {
                $this->notice($op, 'Exception reporting control class selectors are unresolved.');
            }
            $controls[] = ['operation' => $op['method'], 'classes' => $valid, 'callback' => $callback, 'owner' => $op['owner'], 'source' => $op['source'], 'conditions' => [...($active[$op['from']] ?? []), ...$op['conditions']], 'execution_proven' => false];
        }
        foreach ($facts['classes'] as $class) {
            if (! $links->inherits($class['name'], 'Illuminate\Foundation\Exceptions\Handler') || ! array_key_exists('dontReport', $class['properties'])) {
                continue;
            }
            if (count($controls) >= 10000 || ImpactExtractor::sourceLimit(0) !== null) {
                $this->notice(['source' => ['path' => $class['path'], 'line' => $class['line']]], 'Exception reporting controls reached their count or memory budget.');
                break;
            }
            $ids = $this->index->names[strtolower($class['name'].'::$dontReport')] ?? [];
            $property = count($ids) === 1 ? $this->index->elements[$ids[0]] : null;
            if ($property === null) {
                continue;
            }
            $classes = $class['properties']['dontReport'];
            $valid = is_array($classes) && array_is_list($classes) ? array_values(array_filter($classes, fn ($type) => is_string($type) && preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*$/D', $type))) : [];
            if (! is_array($classes) || ! array_is_list($classes) || count($valid) !== count($classes)) {
                $this->notice(['source' => ['path' => $property['path'], 'line' => $property['line']]], 'Handler dontReport property class selectors are unresolved.');
            }
            $controls[] = ['operation' => 'dontreport-property', 'classes' => $valid, 'callback' => null, 'owner' => $class['name'], 'source' => ['path' => $property['path'], 'line' => $property['line'], 'offset' => $property['offset']], 'conditions' => ['The source Handler must be selected; inherited property overrides and runtime configuration may replace these selectors.'], 'execution_proven' => false];
        }

        $relations = 0;
        foreach ($controls as $control) {
            $fileId = CatalogElement::identity($control['source']['path'], 'file', $control['source']['path']);
            foreach ($control['classes'] === [] ? [null] : $control['classes'] as $type) {
                if (++$relations > 10000 || ImpactExtractor::sourceLimit(0) !== null) {
                    $this->notice(['source' => $control['source']], 'Exception reporting control relations reached their count or memory budget.');

                    return $controls;
                }
                $target = $fileId;
                if (is_string($type)) {
                    $ids = $this->index->namedTypes($type);
                    $target = count($ids) === 1 ? $ids[0] : CatalogElement::resourceIdentity('exception-type', $type);
                    if (! isset($this->index->elements[$target])) {
                        $source = $control['source'];
                        $element = new CatalogElement($target, $type, 'exception-type', $source['line'], $source['line'], $source['offset'], metadata: ['external' => true, 'exception_contract_known' => $this->exceptionContract($type) === true]);
                        $this->index->elements[$target] = [...$element->toArray(), 'path' => $source['path'], 'knowledge' => 'static', 'role_evidence' => [], 'sources' => [$source]];
                        $this->index->names[strtolower($type)][] = $target;
                    }
                }
                $this->index->addRelation(['from' => $fileId, 'to' => $target, 'kind' => 'exception-report-control', 'path' => $control['source']['path'], 'line' => $control['source']['line'], 'end_line' => $control['source']['line'], 'resolution' => 'conditional', 'knowledge' => 'static', 'metadata' => $control]);
            }
        }

        return $controls;
    }

    /** @param array<string, mixed> $op */
    private function notice(array $op, string $message): void
    {
        $this->index->diagnostics[] = ['code' => str_contains($message, 'budget') ? 'exception_composition_limit' : 'exception_analysis', 'message' => $message, 'path' => $op['source']['path'], 'line' => $op['source']['line'], 'subject' => null];
    }
}
