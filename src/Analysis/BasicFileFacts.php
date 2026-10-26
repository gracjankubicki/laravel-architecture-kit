<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Analysis;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntry;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntryCodec;
use InvalidArgumentException;

/** A full local map contribution. It is neither portable syntax nor a completed audit. */
final readonly class BasicFileFacts
{
    public const PRODUCT = 'basic-file-facts-v1';

    public const EXTRACTOR = 1;

    /** @param list<array{category: string, message: string, start: int|null, end: int|null}> $diagnostics */
    private function __construct(
        public SourceInput $source,
        public EngineDescriptor $engine,
        public BasicFactsOptions $options,
        public FileGraphEntry $entry,
        public array $diagnostics,
        public string $mappingFingerprint,
    ) {}

    /** Native records use typed identity references and unclassified symbol rows.
     * PHP materializes existing IDs and roles; no syntax or source extraction occurs here. */
    public static function fromNative(mixed $data, BasicSource $input, EngineDescriptor $engine, BasicFactsOptions $options, ?BasicRecordStrings $strings = null): self
    {
        FileGraphEntryCodec::keys($data, ['schema', 'product', 'extractor', 'raw_schema', 'path', 'source_hash', 'php_version', 'engine_fingerprint', 'configuration_fingerprint', 'mapping_fingerprint', 'identities', 'entry', 'diagnostics', 'trace']);
        if ($data['raw_schema'] !== 1 || ! is_array($data['identities']) || ! is_array($data['trace'])
            || ($data['trace']['product'] ?? null) !== self::PRODUCT || ($data['trace']['syntax_transport'] ?? null) !== false
            || ! is_bool($data['trace']['ir_built'] ?? null)) {
            throw new InvalidArgumentException('Invalid native Basic record schema or product trace.');
        }
        $identities = new BasicRecordIdentities($input->original, $data['identities'], $strings ?? new BasicRecordStrings);
        $entry = $identities->materialize($data['entry']);
        if (! is_array($entry) || ! is_array($entry['symbols'] ?? null) || ! array_is_list($entry['symbols'])) {
            throw new InvalidArgumentException('Native Basic contribution needs a symbol list.');
        }
        foreach ($entry['symbols'] as &$symbol) {
            if (! is_array($symbol) || ! array_is_list($symbol) || count($symbol) !== 6
                || ! is_string($symbol[0]) || ! is_int($symbol[1]) || ! is_string($symbol[2]) || ! is_string($symbol[3])
                || ! is_bool($symbol[4]) || ! is_bool($symbol[5])) {
                throw new InvalidArgumentException('Invalid native Basic symbol.');
            }
            $symbol = [$symbol[0], $symbol[1], $symbol[2], $symbol[3], $options->roles->classify($input->original->path, $symbol[0], $symbol[3], $symbol[5]), $symbol[4]];
        }
        unset($symbol);
        unset($data['raw_schema'], $data['identities'], $data['trace']);
        $data['entry'] = $entry;

        return self::decode($data, $input->original, $engine, $options, $input->mappingFingerprint);
    }

    /** The caller supplies the expected snapshot, settings and prepared-source identity. */
    public static function decode(mixed $data, SourceInput $source, EngineDescriptor $engine, BasicFactsOptions $options, string $mappingFingerprint = ''): self
    {
        $engine->requireCapability(self::PRODUCT);
        FileGraphEntryCodec::keys($data, ['schema', 'product', 'extractor', 'path', 'source_hash', 'php_version', 'engine_fingerprint', 'configuration_fingerprint', 'mapping_fingerprint', 'entry', 'diagnostics']);
        if ($data['schema'] !== EngineDescriptor::SCHEMA || $data['product'] !== self::PRODUCT || $data['extractor'] !== self::EXTRACTOR
            || $data['path'] !== $source->path || $data['source_hash'] !== $source->hash || $data['php_version'] !== $source->phpVersion
            || $data['engine_fingerprint'] !== $engine->fingerprint() || $data['configuration_fingerprint'] !== $options->fingerprint()
            || $data['mapping_fingerprint'] !== $mappingFingerprint) {
            throw new InvalidArgumentException('Basic contribution belongs to another product, source, implementation or extraction configuration.');
        }
        // Reuse the syntax diagnostic validator without pretending that an empty
        // syntax list is a tree suitable for portable rules.
        $diagnostics = (new AnalysisDocument($source, $engine, [], $data['diagnostics']))->diagnostics;
        $entry = FileGraphEntryCodec::decode($data['entry'], $source, $options);
        if (($diagnostics !== []) !== ($entry->syntaxError !== null)) {
            throw new InvalidArgumentException('Basic contribution syntax status disagrees with its diagnostics.');
        }
        if ($diagnostics !== [] && ($entry->syntaxError !== $diagnostics[0]['message']
            || $entry->syntaxErrorLine !== $source->lineAt($diagnostics[0]['start'] ?? 0))) {
            throw new InvalidArgumentException('Basic contribution syntax position disagrees with its diagnostics.');
        }

        return new self($source, $engine, $options, $entry, $diagnostics, $mappingFingerprint);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([self::PRODUCT, self::EXTRACTOR, $this->source->fingerprint($this->engine),
            $this->options->fingerprint(), $this->mappingFingerprint], JSON_THROW_ON_ERROR));
    }
}
