<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\NativeGraph;

use Generator;
use GracjanKubicki\ArchitectureKit\Catalog\ComposerCatalogExtractor;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureStore;
use RuntimeException;

/** Only accepts complete, validated R01 datasets. Never reads original sources. */
final readonly class NativeReplay
{
    public function __construct(private NativeGraphClient $client) {}

    /**
     * @param  array<array-key, mixed>  $captureIdentity
     * @param  array<string, string>  $identity
     */
    public function load(string $directory, array $captureIdentity, array $identity): string
    {
        $manifest = CaptureStore::manifest($directory, $captureIdentity);
        $scope = [];
        foreach ($manifest['source_plan']['files'] as $owner => $signature) {
            $scope[] = ['owner' => $owner, 'sha256' => substr($signature, -64),
                'kind' => in_array($owner, ComposerCatalogExtractor::FILES, true) ? 'composer' : (str_ends_with($owner, '.blade.php') ? 'blade' : 'php'),
                'php_version' => $manifest['source_plan']['php_version']];
        }
        $ingest = $this->client->request(['op' => 'begin', 'identity' => $identity, 'options' => $manifest['options'], 'scope' => $scope])['ingest'];
        unset($scope);
        try {
            foreach (CaptureStore::replay($directory, $captureIdentity) as $item) {
                $this->client->request(['op' => 'append', 'ingest' => $ingest, 'record' => $item['record']]);
                if (in_array('basic-graph-v1', $this->client->descriptor['capabilities'] ?? [], true)) {
                    (new NativeBasic($this->client))->enrich($ingest, $item['record']);
                }
            }

            return $this->client->request(['op' => 'commit', 'ingest' => $ingest])['generation'];
        } catch (\Throwable $error) {
            try {
                $this->client->request(['op' => 'abort', 'ingest' => $ingest]);
            } catch (\Throwable) {
                // Preserve the first error. Runtime drops failed ingests itself.
            }
            throw $error;
        }
    }

    /**
     * Bounded export for validation/tools, never used to build a second PHP index.
     *
     * @param  array<string, string>  $identity
     * @return Generator<int, array<array-key, mixed>>
     */
    public function records(string $generation, array $identity): Generator
    {
        $lease = $this->client->request(['op' => 'pin', 'generation' => $generation, 'identity' => $identity])['lease'];
        $start = 0;
        try {
            do {
                $page = $this->client->request(['op' => 'read_batch', 'generation' => $generation, 'identity' => $identity, 'lease' => $lease, 'start' => $start, 'limit' => 1]);
                foreach ($page['records'] as $record) {
                    yield $record;
                }
                if ($page['next'] !== null && $page['next'] <= $start) {
                    throw new RuntimeException('native_graph_cursor');
                }
                $start = $page['next'];
            } while ($start !== null);
        } finally {
            if ($this->client->healthy()) {
                $this->client->request(['op' => 'release', 'lease' => $lease]);
            }
        }
    }
}
