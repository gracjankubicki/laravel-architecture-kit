<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\Capture;

use GracjanKubicki\ArchitectureKit\Analysis\BasicFactsOptions;
use GracjanKubicki\ArchitectureKit\Analysis\BasicSource;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntry;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntryCodec;
use GracjanKubicki\ArchitectureKit\Catalog\ComposerCatalogExtractor;
use InvalidArgumentException;

final class CaptureRecord
{
    /**
     * @return array<array-key, mixed>
     */
    public static function create(int $ordinal, string $kind, BasicSource $source, BasicFactsOptions $options, FileGraphEntry $entry): array
    {
        foreach ([$entry->symbols, $entry->edges, $entry->testInvocations] as $records) {
            foreach ($records as $item) {
                if ($item->path !== $source->original->path) {
                    throw new InvalidArgumentException('capture_entry_owner');
                }
            }
        }
        foreach ([$entry->impact, $entry->catalog] as $facts) {
            if ($facts !== null && $facts->path !== $source->original->path) {
                throw new InvalidArgumentException('capture_entry_owner');
            }
        }
        $payload = FileGraphEntryCodec::encode($entry);
        // Validate against the actual source before it is released.
        $decoded = FileGraphEntryCodec::decode($payload, $source->original, $options);
        if ($payload !== FileGraphEntryCodec::encode($decoded)) {
            throw new InvalidArgumentException('capture_lossy_entry');
        }

        return ['ordinal' => $ordinal, 'owner' => $source->original->path, 'kind' => $kind,
            'sha256' => $source->original->hash, 'php_version' => $source->original->phpVersion,
            'options' => $options->toArray(), 'witness' => SourceWitness::fromSource($source), 'entry' => TypedValue::encode($payload)];
    }

    /**
     * @param  array<array-key, mixed>  $record
     * @param  array<array-key, mixed>  $options
     */
    public static function decode(array $record, array $options): FileGraphEntry
    {
        if (array_keys($record) !== ['ordinal', 'owner', 'kind', 'sha256', 'php_version', 'options', 'witness', 'entry']
            || ! is_int($record['ordinal']) || $record['ordinal'] < 0 || ! is_string($record['owner']) || $record['owner'] === ''
            || ! in_array($record['kind'], ['php', 'blade', 'composer'], true) || $record['options'] !== $options
            || ! is_array($record['witness']) || $record['sha256'] !== ($record['witness']['original']['sha256'] ?? null)) {
            throw new InvalidArgumentException('capture_record_identity');
        }
        $kind = in_array($record['owner'], ComposerCatalogExtractor::FILES, true) ? 'composer' : (str_ends_with($record['owner'], '.blade.php') ? 'blade' : 'php');
        if ($record['kind'] !== $kind) {
            throw new InvalidArgumentException('capture_record_kind');
        }
        $input = SourceWitness::input($record['owner'], $record['php_version'], $record['witness']);
        $payload = TypedValue::decode($record['entry']);
        $entry = FileGraphEntryCodec::decode($payload, $input, new BasicFactsOptions($options['impact'], $options['catalog'], $options['preserve_occurrences']));
        if ($payload !== FileGraphEntryCodec::encode($entry)) {
            throw new InvalidArgumentException('capture_replay_loss');
        }

        return $entry;
    }
}
