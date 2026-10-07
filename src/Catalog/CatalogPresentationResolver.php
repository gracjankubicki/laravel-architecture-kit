<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use Illuminate\Support\Str;

/** Source registrations and conventional component candidates; no view engine or container. */
final class CatalogPresentationResolver
{
    private int $visited = 0;

    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    public function resolve(): void
    {
        $views = [];
        $viewNames = [];
        $registrations = [];
        $components = [];
        foreach ($this->index->relations as $edge) {
            if ($edge['kind'] === 'view-source') {
                $views[$edge['from']] = $this->index->elements[$edge['from']]['name'];
                $viewNames[$views[$edge['from']]][] = $edge['from'];
            } elseif ($edge['kind'] === 'registers-component') {
                $registrations[$edge['from']][] = $edge;
            }
        }
        foreach ($this->index->elements as $id => $element) {
            if ($element['kind'] === 'class' && str_starts_with($element['name'], 'App\\View\\Components\\')
                && $this->index->hasContract($id, 'Illuminate\\View\\Component')) {
                $suffix = substr($element['name'], strlen('App\\View\\Components\\'));
                $name = implode('.', array_map(Str::kebab(...), explode('\\', $suffix)));
                $components[$name][] = $id;
            }
        }
        $edges = $this->index->relations;
        foreach ($edges as $position => $edge) {
            if (! in_array($edge['kind'], ['registers-view-composer-handler', 'registers-view-creator-handler', 'view-composer', 'view-creator'], true)) {
                continue;
            }
            if ($this->index->namedTypes('Illuminate\\Support\\Facades\\View') !== []) {
                if (in_array($edge['kind'], ['view-composer', 'view-creator'], true)) {
                    $this->index->relations[$position]['kind'] = 'references-view-callback';
                    $this->index->relations[$position]['resolution'] = 'conditional';
                    $this->index->relations[$position]['metadata']['contract_verified'] = false;
                }
                $this->diagnostic($edge, 'Source View facade shadow prevents callback inference.');

                continue;
            }
            $target = $this->index->elements[$edge['to']] ?? null;
            $kind = str_contains($edge['kind'], 'composer') ? 'view-composer' : 'view-creator';
            if ($target !== null && $target['kind'] === 'closure') {
                $targets = [$target['id']];
            } elseif ($target !== null && $target['kind'] === 'class') {
                $targets = $this->method($target['name'], $edge['metadata']['callback_method'] ?? ($kind === 'view-composer' ? 'compose' : 'create'), $edge);
            } else {
                $targets = [];
            }
            if ($targets === []) {
                $this->diagnostic($edge, 'View callback body is missing, ambiguous or inaccessible.');
            }
            $selector = $this->index->elements[$edge['from']]['name'];
            foreach ($views as $view => $name) {
                if (++$this->visited > 25000) {
                    $this->diagnostic($edge, 'Presentation composition budget reached.', 'presentation_limit');

                    return;
                }
                if (! $this->matches($selector, $name)) {
                    continue;
                }
                foreach ($targets as $targetId) {
                    if ($view === $edge['from'] && $targetId === $edge['to'] && $edge['kind'] === $kind) {
                        continue;
                    }
                    $this->index->addRelation([...$edge, 'from' => $view, 'to' => $targetId, 'kind' => $kind, 'resolution' => 'conditional',
                        'metadata' => [...$edge['metadata'], 'selector' => $selector, 'execution_proven' => false]]);
                }
            }
        }
        foreach ($this->index->elements as $tag => $element) {
            if ($element['kind'] !== 'blade-component-tag') {
                continue;
            }
            $evidence = ['from' => $tag, 'path' => $element['path'], 'line' => $element['line'], 'end_line' => $element['end_line']];
            if (++$this->visited > 25000) {
                $this->diagnostic($evidence, 'Presentation composition budget reached.', 'presentation_limit');

                return;
            }
            $explicit = $registrations[$tag] ?? [];
            $targets = [];
            if ($explicit !== []) {
                if ($this->index->namedTypes('Illuminate\\Support\\Facades\\Blade') !== []) {
                    $this->diagnostic($evidence, 'Source Blade facade shadow prevents component registration inference.');

                    continue;
                }
                foreach ($explicit as $registration) {
                    if (isset($this->index->elements[$registration['to']])) {
                        $targets[] = $registration['to'];
                    }
                }
                $targets = array_values(array_unique($targets));
                $evidence = $explicit[0];
            } else {
                $targets = $components[$element['name']] ?? [];
            }
            if (count($targets) > 1) {
                $this->diagnostic($evidence, 'Several component declarations or registrations match this tag.');
            }
            if ($targets !== []) {
                foreach ($targets as $id) {
                    $component = $this->index->elements[$id];
                    if (! $this->index->hasContract($id, 'Illuminate\\View\\Component') || $this->index->namedTypes('Illuminate\\View\\Component') !== []) {
                        $this->diagnostic($evidence, 'Component class contract is unresolved.');

                        continue;
                    }
                    $renders = $this->method($component['name'], 'render', $evidence);
                    if ($renders === []) {
                        $this->diagnostic($evidence, 'Component render method is missing, ambiguous or inaccessible.');
                    }
                    foreach ($renders as $render) {
                        $this->index->addRelation([...$evidence, 'from' => $tag, 'to' => $render, 'kind' => 'component-render', 'knowledge' => 'static', 'resolution' => 'conditional',
                            'metadata' => ['component_class' => $id, 'basis' => $explicit === [] ? 'placement-convention' : 'source-registration', 'execution_proven' => false]]);
                    }
                }
            } elseif ($explicit === []) {
                $name = 'components.'.$element['name'];
                $found = false;
                foreach ($viewNames[$name] ?? [] as $view) {
                    $found = true;
                    $this->index->addRelation([...$evidence, 'to' => $view, 'kind' => 'component-view', 'knowledge' => 'static', 'resolution' => 'conditional',
                        'metadata' => ['basis' => 'placement-convention', 'execution_proven' => false]]);
                }
                if (! $found) {
                    $this->diagnostic($evidence, 'Component tag has no source-resolvable class, registration or anonymous view.');
                }
            } else {
                $this->diagnostic($evidence, 'Registered component class is outside the analyzed graph.');
            }
        }
    }

    /** @param array<string, mixed> $edge
     * @return list<string>
     */
    private function method(string $class, string $method, array $edge): array
    {
        $result = $this->calls->sourceCallableCandidates($class, $method, true, ['creator' => '(framework-view)', 'form' => 'instance', 'binding' => null]);
        if ($result['limited']) {
            $this->diagnostic($edge, 'View callback method resolution budget reached.', 'presentation_limit');
        }

        return $result['targets'];
    }

    private function matches(string $selector, string $name): bool
    {
        return $selector === $name || str_contains($selector, '*') && preg_match('/\A'.str_replace('\\*', '.*', preg_quote($selector, '/')).'\z/D', $name) === 1;
    }

    /** @param array<string, mixed> $edge */
    private function diagnostic(array $edge, string $message, string $code = 'presentation_analysis'): void
    {
        $this->index->diagnostics[] = ['code' => $code, 'message' => $message, 'path' => $edge['path'], 'line' => $edge['line'], 'subject' => $edge['from']];
    }
}
