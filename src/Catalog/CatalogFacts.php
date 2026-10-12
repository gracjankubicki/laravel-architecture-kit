<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use InvalidArgumentException;

/** File-local facts share the existing parse/cache lifecycle; no AST survives here. */
final readonly class CatalogFacts
{
    public const VERSION = 3;

    /** @param list<CatalogElement> $elements
     * @param  list<CatalogRelation>  $relations
     * @param  list<CatalogDiagnostic>  $diagnostics
     * @param  array<string, string>  $descriptorReceivers
     */
    public function __construct(public string $path, public array $elements = [], public array $relations = [], public array $diagnostics = [], public array $descriptorReceivers = [])
    {
        // External arrays enter through fromArray, which validates lists and constructs typed records.
    }

    /** Owner path is supplied by the cache key, never duplicated in local records.
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['v' => self::VERSION, 'elements' => array_map(fn ($item) => $item->toArray(), $this->elements),
            'relations' => array_map(fn ($item) => $item->toArray(), $this->relations),
            'diagnostics' => array_map(fn ($item) => $item->toArray(), $this->diagnostics), 'descriptor_receivers' => $this->descriptorReceivers];
    }

    public static function fromArray(string $path, mixed $value): self
    {
        if (! is_array($value) || array_keys($value) !== ['v', 'elements', 'relations', 'diagnostics', 'descriptor_receivers'] || $value['v'] !== self::VERSION) {
            throw new InvalidArgumentException('Invalid catalog facts version or shape.');
        }

        if (! is_array($value['descriptor_receivers']) || count($value['descriptor_receivers']) > 20000) {
            throw new InvalidArgumentException('Invalid receiver descriptor table.');
        }
        foreach ($value['descriptor_receivers'] as $hash => $receiver) {
            if (! is_string($hash) || ! is_string($receiver) || strlen($receiver) > 16384
                || ! hash_equals(hash('sha256', $receiver), $hash)
                || ! (str_starts_with($receiver, '@return:') || str_starts_with($receiver, '@method-callable:') || str_starts_with($receiver, '@after-argument:') || str_starts_with($receiver, '@property:'))) {
                throw new InvalidArgumentException('Invalid receiver descriptor entry.');
            }
        }
        self::validateDescriptorReferences([$value['elements'], $value['relations'], $value['descriptor_receivers']], $value['descriptor_receivers']);
        foreach (['elements', 'relations', 'diagnostics'] as $key) {
            if (! is_array($value[$key]) || ! array_is_list($value[$key])) {
                throw new InvalidArgumentException('Invalid catalog facts list.');
            }
        }

        return new self($path, array_map(CatalogElement::fromArray(...), $value['elements']),
            array_map(CatalogRelation::fromArray(...), $value['relations']), array_map(CatalogDiagnostic::fromArray(...), $value['diagnostics']), $value['descriptor_receivers']);
    }

    public function estimatedBytes(): int
    {
        // toArray creates flat record arrays. Nested metadata and strings remain
        // shared; count their serialized bytes rather than another full heap copy.
        // The cache applies its existing 3x write margin to this estimate.
        $bytes = 2048;
        foreach ($this->descriptorReceivers as $hash => $receiver) {
            $bytes += 256 + strlen($hash) + strlen($receiver);
        }
        foreach ([$this->elements, $this->relations, $this->diagnostics] as $records) {
            foreach ($records as $record) {
                $bytes += 1024 + self::size($record->toArray());
            }
        }

        return $bytes;
    }

    public function cacheable(): bool
    {
        foreach ($this->diagnostics as $diagnostic) {
            // Source-bounded partial results repeat for unchanged source and package
            // fingerprint. Keep the diagnostic when reused; retry memory/unknown limits.
            if (in_array($diagnostic->code, ['catalog_limit', 'http_limit'], true) && $diagnostic->limitReason === 'structure') {
                continue;
            }
            if (in_array($diagnostic->code, ['data_limit', 'source_limit', 'catalog_limit', 'resource_limit', 'blade_limit', 'call_limit', 'container_limit', 'http_limit', 'execution_limit', 'changed_inputs'], true)) {
                return false;
            }
        }

        return true;
    }

    public static function validateValues(mixed $value, int $depth = 0): void
    {
        if ($depth > 16 || is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('Catalog metadata must contain bounded plain values.');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                self::validateValues($item, $depth + 1);
            }
        }
    }

    /** @param array<string, string> $receivers */
    private static function validateDescriptorReferences(mixed $value, array $receivers, int $depth = 0): void
    {
        if ($depth > 16) {
            throw new InvalidArgumentException('Receiver metadata must be bounded.');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                self::validateDescriptorReferences($item, $receivers, $depth + 1);
            }
        } elseif (is_string($value)) {
            if (str_starts_with($value, '@property:') && ReceiverDescriptors::property($value, $receivers) === null) {
                throw new InvalidArgumentException('Invalid or missing property receiver descriptor reference.');
            }
            foreach (['@return:' => 8, '@method-callable:' => 17, '@after-argument:' => 16] as $prefix => $length) {
                if (str_starts_with($value, $prefix) && ReceiverDescriptors::decode(substr($value, $length), $receivers) === null) {
                    throw new InvalidArgumentException('Invalid or missing receiver descriptor reference.');
                }
            }
        }
    }

    private static function size(mixed $value): int
    {
        if (! is_array($value)) {
            return is_float($value) ? 32 + strlen(serialize($value)) : 32 + (is_string($value) ? strlen($value) : 0);
        }
        $bytes = 32;
        foreach ($value as $key => $item) {
            $bytes += 32 + strlen((string) $key) + self::size($item);
        }

        return $bytes;
    }
}
