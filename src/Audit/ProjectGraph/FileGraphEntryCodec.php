<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph;

use GracjanKubicki\ArchitectureKit\Analysis\BasicFactsOptions;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use GracjanKubicki\ArchitectureKit\Audit\TestReachability\TestInvocation;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Impact\ImpactFacts;
use InvalidArgumentException;

/** Full file contribution, separate from the smaller AnalysisDocument::facts product. */
final class FileGraphEntryCodec
{
    /** @return array<string, mixed> */
    public static function encode(FileGraphEntry $entry): array
    {
        return [
            'symbols' => array_map(static fn (ProjectSymbol $symbol): array => [
                $symbol->name, $symbol->line, $symbol->namespace, $symbol->kind, $symbol->role, $symbol->hasMethods,
            ], $entry->symbols),
            'edges' => array_map(static fn (DependencyEdge $edge): array => [
                $edge->from, $edge->to, $edge->line, $edge->kind, $edge->strong,
            ], $entry->edges),
            'test_invocations' => array_map(static fn (TestInvocation $invocation): array => $invocation->toArray(), $entry->testInvocations),
            'impact' => $entry->impact?->toArray(),
            'catalog' => $entry->catalog?->toArray(),
            'syntax_error' => $entry->syntaxError,
            'syntax_error_line' => $entry->syntaxErrorLine,
        ];
    }

    public static function decode(mixed $data, SourceInput $source, BasicFactsOptions $options): FileGraphEntry
    {
        self::keys($data, ['symbols', 'edges', 'test_invocations', 'impact', 'catalog', 'syntax_error', 'syntax_error_line']);
        foreach (['symbols', 'edges', 'test_invocations'] as $key) {
            if (! is_array($data[$key]) || ! array_is_list($data[$key])) {
                throw new InvalidArgumentException('Basic contribution records must be lists.');
            }
        }
        if (($data['impact'] !== null) !== $options->impact || ($data['catalog'] !== null) !== $options->catalog) {
            throw new InvalidArgumentException('Basic contribution does not match the requested extraction settings.');
        }
        if ($data['syntax_error'] !== null && (! is_string($data['syntax_error']) || $data['syntax_error'] === '')) {
            throw new InvalidArgumentException('Invalid Basic syntax error.');
        }
        self::line($data['syntax_error_line'], $source);
        $symbols = [];
        foreach ($data['symbols'] as $symbol) {
            if (! is_array($symbol) || ! array_is_list($symbol) || count($symbol) !== 6
                || ! is_string($symbol[0]) || $symbol[0] === '' || ! is_string($symbol[2])
                || ! in_array($symbol[3], ['class', 'interface', 'trait', 'enum', 'file'], true)
                || ! is_string($symbol[4]) || $symbol[4] === '' || ! is_bool($symbol[5])) {
                throw new InvalidArgumentException('Invalid Basic project symbol.');
            }
            self::line($symbol[1], $source);
            $symbols[] = new ProjectSymbol($symbol[0], $source->path, $symbol[1], $symbol[2], $symbol[3], $symbol[4], $symbol[5]);
        }
        $edges = [];
        foreach ($data['edges'] as $edge) {
            if (! is_array($edge) || ! array_is_list($edge) || count($edge) !== 5
                || ! is_string($edge[0]) || $edge[0] === '' || ! is_string($edge[1]) || $edge[1] === ''
                || ! in_array($edge[3], ['extends', 'implements', 'trait', 'parameter', 'return', 'property', 'new', 'static', 'instanceof', 'catch', 'attribute', 'eloquent-relation', 'class-reference', 'class-constant'], true)
                || ! is_bool($edge[4])) {
                throw new InvalidArgumentException('Invalid Basic dependency edge.');
            }
            self::line($edge[2], $source);
            $edges[] = new DependencyEdge($edge[0], $edge[1], $source->path, $edge[2], $edge[3], $edge[4]);
        }
        $invocations = [];
        foreach ($data['test_invocations'] as $invocation) {
            $invocation = TestInvocation::fromArray($source->path, $invocation);
            self::line($invocation->line, $source);
            $invocations[] = $invocation;
        }
        $impact = $data['impact'] === null ? null : ImpactFacts::fromArray($source->path, $data['impact']);
        $catalog = $data['catalog'] === null ? null : CatalogFacts::fromArray($source->path, $data['catalog']);
        if ($impact !== null) {
            CatalogFacts::validateValues($impact->toArray());
            foreach ($impact->classes as $class) {
                self::line($class['line'], $source);
                foreach ($class['methods'] as $method) {
                    self::line($method['line'], $source);
                }
            }
            foreach ([...$impact->calls, ...$impact->notices] as $record) {
                self::line($record['line'], $source);
            }
        }
        if ($catalog !== null) {
            $identities = array_fill_keys(array_map(static fn ($element): string => $element->id, $catalog->elements), true);
            foreach ($catalog->elements as $element) {
                self::line($element->line, $source);
                self::line($element->endLine, $source);
                self::offset($element->offset, $source);
                self::positions($element->metadata, $source);
                if (array_key_exists('return_sites', $element->metadata)) {
                    $sites = $element->metadata['return_sites'];
                    self::keys($sites, ['complete', 'offsets', 'limit_reason']);
                    if (! is_bool($sites['complete']) || ! is_array($sites['offsets']) || ! array_is_list($sites['offsets'])
                        || ! in_array($sites['limit_reason'], [null, 'structure', 'memory'], true)) {
                        throw new InvalidArgumentException('Invalid Basic callable return sites.');
                    }
                    foreach ($sites['offsets'] as $offset) {
                        self::offset($offset, $source);
                        if ($offset < $element->offset || $offset > ($element->metadata['end_offset'] ?? -1)) {
                            throw new InvalidArgumentException('Basic return site is outside its callable.');
                        }
                    }
                }
                self::owners($element->metadata, $identities);
                if ($element->parent !== null && (! isset($identities[$element->parent]) || $element->parent === $element->id)) {
                    throw new InvalidArgumentException('Basic catalog parent is not a local declaration.');
                }
            }
            foreach ($catalog->relations as $relation) {
                self::line($relation->line, $source);
                self::line($relation->endLine, $source);
                self::positions($relation->metadata, $source);
                self::owners($relation->metadata, $identities);
                if (! isset($identities[$relation->from]) || str_starts_with($relation->to, 'element:') && ! isset($identities[$relation->to])) {
                    throw new InvalidArgumentException('Basic catalog relation has a missing local owner or target.');
                }
            }
            foreach ($catalog->diagnostics as $diagnostic) {
                self::line($diagnostic->line, $source);
                if ($diagnostic->subject !== null && str_starts_with($diagnostic->subject, 'element:') && ! isset($identities[$diagnostic->subject])) {
                    throw new InvalidArgumentException('Basic diagnostic has a missing local subject.');
                }
            }
        }
        if ($data['syntax_error'] !== null && ($symbols !== [] || $edges !== [] || $invocations !== [] || ($impact !== null && ($impact->classes !== [] || $impact->calls !== [])))) {
            throw new InvalidArgumentException('Basic contribution must not expose recovered PHP facts after a syntax error.');
        }

        return new FileGraphEntry($symbols, $edges, $invocations, $impact, $catalog, $data['syntax_error'], $data['syntax_error_line']);
    }

    /** JSON object member order is not part of the wire contract.
     * @param list<string> $keys */
    public static function keys(mixed $data, array $keys): void
    {
        if (! is_array($data) || count($data) !== count($keys) || array_diff($keys, array_keys($data)) !== []) {
            throw new InvalidArgumentException('Invalid or incomplete Basic contribution shape.');
        }
    }

    private static function line(mixed $line, SourceInput $source): void
    {
        if (! is_int($line) || $line < 1 || $line > $source->lineAt(strlen($source->contents))) {
            throw new InvalidArgumentException('Basic contribution line is outside the source snapshot.');
        }
    }

    private static function offset(mixed $offset, SourceInput $source): void
    {
        if (! is_int($offset) || $offset < 0 || $offset > strlen($source->contents)) {
            throw new InvalidArgumentException('Basic contribution offset is outside the source snapshot.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private static function positions(array $metadata, SourceInput $source): void
    {
        foreach (['offset', 'end_offset', 'receiver_origin', 'receiver_instance_origin', 'value_origin'] as $key) {
            if (array_key_exists($key, $metadata) && $metadata[$key] !== null) {
                self::offset($metadata[$key], $source);
            }
        }
    }

    /** @param array<string, mixed> $metadata
     * @param array<string, true> $identities */
    private static function owners(array $metadata, array $identities): void
    {
        foreach ([$metadata['callback'] ?? null, $metadata['registration_scope'] ?? null, $metadata['bound_callable']['creator'] ?? null] as $owner) {
            if ($owner !== null && (! is_string($owner) || ! isset($identities[$owner]))) {
                throw new InvalidArgumentException('Basic metadata has a missing local owner.');
            }
        }
    }
}
