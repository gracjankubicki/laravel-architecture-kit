<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisLimitReason;
use GracjanKubicki\ArchitectureKit\Analysis\GraphBuildEligibility;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntry;
use RuntimeException;

/** Segmented full contributions, separate from the ready CatalogIndex product. */
final class GraphContributionStore
{
    /** @var array{status: string, reason: ?string} */
    public array $lastWrite = ['status' => 'skip', 'reason' => 'unchanged'];

    /** @var array{status: string, reason: ?string} */
    public array $lastRead = ['status' => 'miss', 'reason' => 'missing'];

    public function __construct(private readonly GraphSegmentStore $store, private readonly ?string $skipGeneration = null) {}

    public function read(GraphCacheSignature $signature): CacheReadResult
    {
        $identity = hash('sha256', $signature->fingerprint);
        $result = $this->store->read($identity, ['entries'], static function (string $path, mixed $record) use ($signature): FileGraphEntry {
            if (! SourceManifest::safe('/', $path, true)) {
                throw new RuntimeException('contribution_path');
            }
            $graph = CachedGraph::fromArray(['signature' => $signature->toArray(), 'entries' => [$path => $record]]);
            if ($graph === null) {
                throw new RuntimeException('contribution_shape');
            }

            return $graph->entries[$path];
        }, acceptMetadata: static function (array $metadata): bool {
            if (($reason = GraphBuildEligibility::rejection($metadata['eligibility'] ?? null)) !== null) {
                throw new RuntimeException($reason);
            }
            foreach ($metadata['eligibility']['reasons'] as $reason) {
                GraphBuildEligibility::report(AnalysisLimitReason::from($reason));
            }

            return true;
        }, skipGeneration: $this->skipGeneration);
        $this->lastRead = ['status' => $result['status'], 'reason' => $result['reason']];
        if ($result['status'] !== 'hit' && ($result['decoded'] ?? false)) {
            throw new FreshGraphProcessRequired($result['reason'] ?? 'corrupt', $result['generation'] ?? null, $this->store->directory, 'contributions');
        }
        if ($result['status'] !== 'hit') {
            return new CacheReadResult(null, match ($result['status']) {
                'miss' => CacheStatus::Missing, 'skip' => $result['reason'] === 'budget' ? CacheStatus::TooLarge : CacheStatus::Missing, default => CacheStatus::Corrupt,
            });
        }
        $previous = GraphCacheSignature::fromArray($result['metadata']['signature'] ?? []);
        $entries = $result['state']['entries'];
        if ($previous === null || ! $signature->isCompatibleWith($previous) || array_keys($entries) !== array_keys($previous->files)) {
            throw new FreshGraphProcessRequired('contribution_manifest', $result['generation'] ?? null, $this->store->directory, 'contributions');
        }

        return new CacheReadResult(new CachedGraph($previous, $entries), CacheStatus::Fresh);
    }

    public function write(CachedGraph $graph): bool
    {
        $eligibility = GraphBuildEligibility::current();
        if (($reason = GraphBuildEligibility::rejection($eligibility)) !== null) {
            $this->lastWrite = ['status' => 'skip', 'reason' => $reason];

            return false;
        }
        $records = static function () use ($graph): \Generator {
            foreach ($graph->entries as $path => $entry) {
                yield $path => (new CachedGraph($graph->signature, [$path => $entry]))->toArray()['entries'][$path];
            }
        };
        $this->lastWrite = $this->store->write(hash('sha256', $graph->signature->fingerprint), ['entries' => $records()], ['signature' => $graph->signature->toArray(), 'eligibility' => $eligibility]);

        return $this->lastWrite['status'] === 'success';
    }
}
