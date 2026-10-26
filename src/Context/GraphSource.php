<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Context;

use Closure;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Analysis\GraphBuildEligibility;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\CatalogState;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\FreshGraphProcessRequired;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphContributionStore;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\GraphSegmentStore;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\SourceManifest;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogSettings;
use GracjanKubicki\ArchitectureKit\Discovery\DiscoverySettings;
use GracjanKubicki\ArchitectureKit\Standalone\GraphRuntime;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/** One generation per load; process routing and lifetime belong to the runtime. */
final readonly class GraphSource
{
    /** @param (Closure(string): void)|null $onParse */
    public function __construct(private Filesystem $files, private string $basePath, private ?Closure $onParse = null, private ?AnalysisMode $mode = null) {}

    /** Metadata-only scheduling hint; workers still verify and hydrate the actual product.
     * @return array<string, mixed>|null */
    public function storedMetadata(): ?array
    {
        $identity = SourceManifest::identity($this->basePath, $this->mode ?? AnalysisRuntime::session()->mode);
        $cache = new ProjectGraphCache($this->files, (string) realpath($this->basePath));
        $location = (new GraphSegmentStore($this->files, $cache->canonicalDirectory().'/catalog-locator-v1'))->read($identity, ['location']);
        $directory = $location['state']['location']['directory'] ?? null;
        if (! is_string($directory) || ! DiscoverySettings::safe($this->basePath, $directory)) {
            return null;
        }
        $metadata = (new GraphSegmentStore($this->files,
            (new ProjectGraphCache($this->files, (string) realpath($this->basePath), $directory))->canonicalDirectory().'/catalog-ready-v1'))->metadata($identity);

        return ($metadata['analysis_fingerprint'] ?? null) === $identity ? $metadata : null;
    }

    /** @return array{index: CatalogIndex, snapshot: string, scope: list<string>, changed: bool, analysis: array{mode: string, deep_run: bool, checks_complete: bool}, cache: array<string, mixed>} */
    public function load(?string $rejectedGeneration = null): array
    {
        if (GraphRuntime::inWorker()
            && ($this->mode ?? AnalysisRuntime::session()->mode) === AnalysisMode::Basic
            && ($resident = GraphRuntime::resident()) !== null) {
            return $resident;
        }
        $eligibility = GraphBuildEligibility::begin();
        $complete = false;
        try {
            $result = $this->loadGeneration($rejectedGeneration);
            $complete = true;

            return $result;
        } finally {
            $eligibility->close($complete);
        }
    }

    /** @return array{index: CatalogIndex, snapshot: string, scope: list<string>, changed: bool, analysis: array{mode: string, deep_run: bool, checks_complete: bool}, cache: array<string, mixed>} */
    private function loadGeneration(?string $rejectedGeneration): array
    {
        $mode = $this->mode ?? AnalysisRuntime::session()->mode;
        $identity = SourceManifest::identity($this->basePath, $mode);
        $defaultCache = new ProjectGraphCache($this->files, (string) realpath($this->basePath));
        $locator = new GraphSegmentStore($this->files, $defaultCache->canonicalDirectory().'/catalog-locator-v1');
        $location = $locator->read($identity, ['location'], skipGeneration: $rejectedGeneration);
        $directory = null;
        if ($location['status'] === 'hit') {
            $relative = $location['state']['location']['directory'] ?? null;
            if (! is_string($relative) || ! DiscoverySettings::safe($this->basePath, $relative)
                || ($location['metadata']['repository_id'] ?? null) !== hash('sha256', (string) realpath($this->basePath))
                || ($location['metadata']['analysis_fingerprint'] ?? null) !== $identity) {
                throw new FreshGraphProcessRequired('locator_boundary', $location['generation'] ?? null, $locator->directory, 'locator');
            }
            $directory = (new ProjectGraphCache($this->files, (string) realpath($this->basePath), $relative))->canonicalDirectory().'/catalog-ready-v1';
        } elseif ($location['decoded'] ?? false) {
            throw new FreshGraphProcessRequired($location['reason'] ?? 'corrupt', $location['generation'] ?? null, $locator->directory, 'locator');
        }
        $signatures = [];
        $acceptMetadata = function (array $metadata) use ($identity, $mode, &$signatures): bool {
            if (($reason = GraphBuildEligibility::rejection($metadata['eligibility'] ?? null)) !== null) {
                throw new \RuntimeException($reason);
            }
            if (($metadata['repository_id'] ?? null) !== hash('sha256', (string) realpath($this->basePath))
                || ($metadata['analysis_fingerprint'] ?? null) !== $identity
                || ! is_array($metadata['scope'] ?? null) || ! is_array($metadata['exclude'] ?? null)) {
                return false;
            }
            $signatures = SourceManifest::scan($this->files, $this->basePath, $metadata['scope'], $metadata['exclude']);

            return ($metadata['input_manifest_digest'] ?? null) === SourceManifest::inputDigest($signatures, SourceManifest::scopeState($this->basePath, $metadata['scope']))
                && SourceManifest::identity($this->basePath, $mode) === $identity;
        };
        $store = $directory === null ? null : new GraphSegmentStore($this->files, $directory);
        $read = $store?->read($identity, CatalogState::FIELDS, acceptMetadata: $acceptMetadata, skipGeneration: $rejectedGeneration)
            ?? ['status' => 'miss', 'reason' => 'locator_missing'];
        if ($read['status'] !== 'hit' && ($read['decoded'] ?? false)) {
            throw new FreshGraphProcessRequired($read['reason'] ?? 'corrupt', $read['generation'] ?? null, $store->directory, 'ready');
        }
        if ($read['status'] === 'hit') {
            try {
                $metadata = $read['metadata'];
                $scope = $metadata['scope'];
                $index = CatalogIndex::fromState($read['state'], SourceManifest::sourceReader($this->files, $this->basePath, $signatures), $mode);
                try {
                    $changed = ! $acceptMetadata($metadata);
                } catch (Throwable) {
                    $changed = true;
                }
                if ($changed) {
                    $index->diagnostics[] = ['code' => 'changed_inputs', 'message' => 'Project inputs changed during restore. Request a new generation.'];
                }

                return $this->result($index, $metadata['snapshot_id'], $scope, $changed, $mode, $metadata['exclude'], $metadata['input_manifest_digest'],
                    ['read' => ['status' => 'hit', 'reason' => $changed ? 'changed_inputs' : null],
                        'write' => ['status' => 'skip', 'reason' => $changed ? 'changed_inputs' : 'unchanged'],
                        'locator_read' => ['status' => $location['status'], 'reason' => $location['reason']], 'generation' => $read['generation']]);
            } catch (Throwable $error) {
                unset($index);
                throw new FreshGraphProcessRequired($error->getMessage(), $read['generation'] ?? null, $store->directory, 'ready');
            }
        }
        // Never keep an old ready state alive beside a newly composed index.
        unset($read['state']);
        $settings = CatalogSettings::load($this->files, $this->basePath, $this->onParse);
        $enabled = $settings->discovery->cache !== null;
        $configuredCache = $settings->discovery->cache;
        $directory = ($configuredCache ?? $defaultCache)->canonicalDirectory().'/catalog-ready-v1';
        $store = new GraphSegmentStore($this->files, $directory);
        $contributions = $enabled ? new GraphContributionStore(new GraphSegmentStore($this->files, $directory.'/contributions'), $rejectedGeneration) : null;
        $loader = new ProjectGraphLoader($this->files, $this->basePath, $settings->scope,
            null, $settings->fingerprint, sourceOnly: true, catalog: true, onParse: $this->onParse, mode: $mode, contributions: $contributions);
        $plan = $loader->plan($settings->exclude);
        $settingsSource = $settings->discovery->sourceFile;
        $parsedSources = $settingsSource?->path === 'config/architectures.php' ? [$settingsSource->path => $settingsSource] : [];
        $facts = $loader->build($plan, $parsedSources)->catalogFacts;
        $signatures = $plan->signature->files;
        $index = new CatalogIndex($facts, SourceManifest::sourceReader($this->files, $this->basePath, $signatures), $mode);
        unset($facts, $plan, $parsedSources, $settingsSource);
        foreach ($settings->diagnostics as $diagnostic) {
            $index->diagnostics[] = [...$diagnostic->toArray(), 'path' => 'config/architectures.php'];
        }
        $scope = $settings->scope->directories;
        $exclude = $settings->exclude;
        $scopeState = $settings->rootState;
        $current = function () use ($scope, $exclude, $signatures, $scopeState, $identity, $mode, $settings): bool {
            try {
                return $settings->fresh($this->basePath)
                    && SourceManifest::identity($this->basePath, $mode) === $identity
                    && SourceManifest::scopeState($this->basePath, $scope) === $scopeState
                    && SourceManifest::scan($this->files, $this->basePath, $scope, $exclude) === $signatures;
            } catch (Throwable) {
                return false;
            }
        };
        $changed = ! $current() || in_array('changed_inputs', array_column($index->diagnostics, 'code'), true);
        if ($changed) {
            $index->diagnostics[] = ['code' => 'changed_inputs', 'message' => 'Project inputs changed during analysis. Restart the query.'];
        }
        $inputDigest = SourceManifest::inputDigest($signatures, $scopeState);
        $snapshot = GraphSegmentStore::digest([$identity, $inputDigest]);
        $eligibility = GraphBuildEligibility::current();
        $rejection = GraphBuildEligibility::rejection($eligibility);
        $write = ['status' => 'skip', 'reason' => ! $enabled ? 'disabled' : ($changed ? 'changed_inputs' : ($rejection ?? 'unchanged'))];
        $locatorWrite = ['status' => 'skip', 'reason' => 'unchanged'];
        $readyWrite = $write;
        if ($enabled && ! $changed && $rejection === null) {
            $write = $store->write($identity, $index->exportState(), ['repository_id' => hash('sha256', (string) realpath($this->basePath)),
                'analysis_fingerprint' => $identity, 'input_manifest_digest' => $inputDigest,
                'snapshot_id' => $snapshot, 'scope' => $scope, 'exclude' => $exclude, 'eligibility' => $eligibility, 'input_files' => $signatures], $current);
            $readyWrite = $write;
            if ($write['status'] === 'success' && $location['status'] !== 'hit') {
                $relative = $configuredCache->configuredDirectory();
                if (! DiscoverySettings::safe($this->basePath, $relative)) {
                    $locatorWrite = ['status' => 'error', 'reason' => 'locator_boundary'];
                } else {
                    $locatorWrite = $locator->write($identity, ['location' => ['directory' => $relative]],
                        ['repository_id' => hash('sha256', (string) realpath($this->basePath)), 'analysis_fingerprint' => $identity], $current);
                }
                if ($locatorWrite['status'] !== 'success') {
                    $write = ['status' => $locatorWrite['status'], 'reason' => 'locator:'.$locatorWrite['reason']];
                }
            }
            $changed = $write['reason'] === 'changed_inputs' || $locatorWrite['reason'] === 'changed_inputs' || ! $current();
            if ($changed) {
                $index->diagnostics[] = ['code' => 'changed_inputs', 'message' => 'Project inputs changed during publication. Request a new generation.'];
            }
        }

        return $this->result($index, $snapshot, $scope, $changed, $mode, $exclude, $inputDigest,
            ['read' => $enabled ? ['status' => $read['status'], 'reason' => $read['reason']] : ['status' => 'skip', 'reason' => 'disabled'],
                'write' => $write, 'ready_write' => $readyWrite, 'locator_write' => $locatorWrite,
                'locator_read' => ['status' => $location['status'], 'reason' => $location['reason']],
                'contributions_read' => $contributions->lastRead ?? ['status' => 'skip', 'reason' => 'disabled'],
                'contributions_write' => $contributions->lastWrite ?? ['status' => 'skip', 'reason' => 'disabled']]);
    }

    /** @param list<string> $scope
     * @param list<string> $exclude
     * @param array<string, mixed> $cache
     * @return array{index: CatalogIndex, snapshot: string, scope: list<string>, changed: bool, analysis: array{mode: string, deep_run: bool, checks_complete: bool}, cache: array<string, mixed>} */
    private function result(CatalogIndex $index, string $snapshot, array $scope, bool $changed, AnalysisMode $mode, array $exclude, string $inputDigest, array $cache): array
    {
        return ['index' => $index, 'snapshot' => $snapshot, 'scope' => $scope, 'changed' => $changed,
            'analysis' => ['mode' => $mode->value, 'deep_run' => $mode === AnalysisMode::Deep, 'checks_complete' => ! $changed && $index->checksComplete()],
            'cache' => $cache, 'metadata' => ['scope' => $scope, 'exclude' => $exclude, 'input_manifest_digest' => $inputDigest]];
    }
}
