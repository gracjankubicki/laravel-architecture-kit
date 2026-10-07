<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ExecutionLinks;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

/** File registration witnesses do not imply execution of the file's declarations. */
final class CatalogConsoleRegistrationResolver
{
    private int $visits = 0;

    public function __construct(private readonly CatalogIndex $index) {}

    /** @param array<string, mixed> $facts */
    public function resolve(array $facts, ExecutionLinks $links): void
    {
        foreach ($facts['operations'] as $op) {
            $values = null;
            $filesOnly = false;
            if ($op['kind'] === 'console_registration' && $op['method'] === 'withrouting' && ($op['args']['commands_declared'] ?? false)) {
                if ($op['args']['commands_null']) {
                    continue;
                }
                $values = $op['args']['commands'];
                $filesOnly = true;
                if ($values === null) {
                    $this->notice($op['source'], 'Console route file is dynamic; no file registration is established.');

                    continue;
                }
            } elseif ($op['kind'] === 'console_registration' && $op['method'] === 'withcommands') {
                $values = $op['args']['commands'];
            } elseif ($op['kind'] === 'console_candidate' && $op['method'] === 'addcommandroutepaths'
                && is_string($op['owner']) && $links->inherits($op['owner'], 'Illuminate\Foundation\Console\Kernel') && $links->method($op['owner'], $op['method']) === null) {
                $values = $op['args'][0] ?? null;
                $filesOnly = true;
            } else {
                continue;
            }
            foreach (is_array($values) && array_is_list($values) ? $values : [$values] as $value) {
                if (! $this->room($op['source'])) {
                    return;
                }
                if (! is_string($value)) {
                    if ($filesOnly) {
                        $this->notice($op['source'], 'Console route file selector is dynamic or unresolved.');
                    }

                    continue;
                }
                // withCommands also accepts class names and directories, handled by the console channel.
                if (! $filesOnly && ! str_ends_with($value, '.php')) {
                    continue;
                }
                $path = $this->path($value);
                $target = $path === null ? null : CatalogElement::identity($path, 'file', $path);
                if ($target === null || ! isset($this->index->elements[$target])) {
                    $this->notice($op['source'], 'Console route file is absent from the declared source graph or its path is unsafe.');

                    continue;
                }
                $origin = str_starts_with($op['from'], '(file) ')
                    ? CatalogElement::identity(substr($op['from'], 7), 'file', substr($op['from'], 7)) : null;
                if ($origin === null) {
                    $name = str_starts_with($op['from'], '(callback) ') ? '(closure) '.substr($op['from'], 11) : $op['from'];
                    $ids = $this->index->names[strtolower($name)] ?? [];
                    $origin = count($ids) === 1 ? $ids[0] : null;
                }
                if ($origin === null || ! isset($this->index->elements[$origin])) {
                    $this->notice($op['source'], 'Console file registration owner is absent or ambiguous.');

                    continue;
                }
                $witness = ['source' => $op['source'], 'operation' => $op['method'], 'conditions' => $op['conditions'], 'execution_proven' => false];
                $this->index->addRelation(['from' => $origin, 'to' => $target, 'kind' => 'registers-console-file', 'path' => $op['source']['path'],
                    'line' => $op['source']['line'], 'end_line' => $op['source']['line'], 'resolution' => 'conditional', 'knowledge' => 'static', 'metadata' => $witness]);
                $queue = [[$target, []]];
                $seen = [];
                while ($queue !== []) {
                    [$file, $includes] = array_shift($queue);
                    if (isset($seen[$file])) {
                        continue;
                    }
                    $seen[$file] = true;
                    if (! $this->room($op['source'])) {
                        return;
                    }
                    $this->index->elements[$file]['metadata']['console_file_registrations'][] = [...$witness, 'include_sources' => $includes];
                    foreach ($this->index->out[$file] ?? [] as $position) {
                        $edge = $this->index->relations[$position];
                        if ($edge['kind'] === 'includes-file' && ($this->index->elements[$edge['to']]['kind'] ?? null) === 'file') {
                            $queue[] = [$edge['to'], [...$includes, ['path' => $edge['path'], 'line' => $edge['line']]]];
                        }
                    }
                }
            }
        }
    }

    private function path(string $path): ?string
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, ':') || str_contains($path, "\0")) {
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

    /** @param array<string, mixed> $source
     * @phpstan-impure
     */
    private function room(array $source): bool
    {
        if (++$this->visits <= 10000 && ImpactExtractor::sourceLimit(0) === null) {
            return true;
        }
        $this->notice($source, 'Console file registration composition reached its operation or memory budget.');

        return false;
    }

    /** @param array<string, mixed> $source */
    private function notice(array $source, string $message): void
    {
        $this->index->diagnostics[] = ['code' => 'console_file_registration', 'message' => $message, 'path' => $source['path'], 'line' => $source['line'], 'subject' => null];
    }
}
