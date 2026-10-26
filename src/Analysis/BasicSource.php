<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

use InvalidArgumentException;

/** Original snapshot plus the versioned byte mapping of prepared PHP, without parsing. */
final readonly class BasicSource
{
    public string $mappingFingerprint;

    /** @param list<array{start: int, original: int, length: int, copied: bool}> $segments */
    public function __construct(public SourceInput $original, public SourceInput $prepared, public array $segments = [], public string $mappingVersion = '')
    {
        if ($original->path !== $prepared->path || $original->phpVersion !== $prepared->phpVersion || ! array_is_list($segments)) {
            throw new InvalidArgumentException('Prepared PHP must belong to the original source snapshot.');
        }
        if ($segments === []) {
            if ($original->contents !== $prepared->contents || $mappingVersion !== '') {
                throw new InvalidArgumentException('A transformed source needs an explicit byte mapping.');
            }
            $this->mappingFingerprint = '';

            return;
        }
        if ($mappingVersion === '') {
            throw new InvalidArgumentException('A transformed source needs a mapping version.');
        }
        $cursor = 0;
        foreach ($segments as $segment) {
            if (array_keys($segment) !== ['start', 'original', 'length', 'copied'] || ! is_int($segment['start']) || $segment['start'] !== $cursor
                || ! is_int($segment['original']) || $segment['original'] < 0 || $segment['original'] > strlen($original->contents)
                || ! is_int($segment['length']) || $segment['length'] < 1 || ! is_bool($segment['copied'])
                || $segment['copied'] && strlen($original->contents) < $segment['original'] + $segment['length']) {
                throw new InvalidArgumentException('Invalid prepared PHP byte mapping.');
            }
            $cursor += $segment['length'];
        }
        if ($cursor !== strlen($prepared->contents)) {
            throw new InvalidArgumentException('Prepared PHP byte mapping must cover the whole input.');
        }
        $this->mappingFingerprint = hash('sha256', json_encode([$original->hash, $prepared->hash, $mappingVersion, $segments], JSON_THROW_ON_ERROR));
    }

    public static function plain(SourceInput $source): self
    {
        return new self($source, $source);
    }

    /** @return array<string, mixed> */
    public function request(EngineDescriptor $engine, BasicFactsOptions $options): array
    {
        return ['schema' => EngineDescriptor::SCHEMA, 'product' => BasicFileFacts::PRODUCT, 'extractor' => BasicFileFacts::EXTRACTOR,
            'path' => $this->original->path, 'source' => $this->prepared->contents, 'source_hash' => $this->original->hash,
            'php_version' => $this->original->phpVersion, 'engine_fingerprint' => $engine->fingerprint(),
            'configuration_fingerprint' => $options->fingerprint(), 'mapping_fingerprint' => $this->mappingFingerprint,
            'options' => $options->toArray(), ...($this->segments === [] ? [] : ['original_source' => $this->original->contents, 'mapping' => $this->segments])];
    }
}
