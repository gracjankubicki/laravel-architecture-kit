<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\NativeGraph;

use GracjanKubicki\ArchitectureKit\Context\GraphPage;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\TypedValue;
use RuntimeException;

/** Product reads one bounded page. Never collects a native product or the complete query. */
final readonly class NativePages
{
    public function __construct(private NativeGraphClient $client) {}

    /** @param array<string, string> $identity
     * @param array<string, mixed> $query
     * @param array<string, mixed> $arguments
     * @param list<string> $scope
     * @param array<string, int> $summary
     * @return array<string, mixed> */
    public function page(string $generation, array $identity, array $query, array $arguments, array $scope, array $summary = [], int $maxLimit = 100, ?string $existingLease = null): array
    {
        $offset = $arguments['offset'] ?? 0;
        $limit = $arguments['limit'] ?? 20;
        $command = in_array($query['type'], ['search', 'discovery'], true) ? 'architecture-search' : 'architecture-graph';
        $field = in_array($query['type'], ['search', 'discovery'], true) ? 'candidates' : 'records';
        if (! is_int($offset) || $offset < 0 || ! is_int($limit) || $limit < 0 || $limit > ($query['type'] === 'discovery' ? 500 : $maxLimit) || $offset > 0 && ! is_string($arguments['snapshot'] ?? null) || isset($arguments['snapshot']) && ! is_string($arguments['snapshot'])) {
            return GraphPage::make($command, [], $generation, $scope, [], ['status' => 'invalid_input', $field => []], $field);
        }
        if (isset($arguments['snapshot']) && $arguments['snapshot'] !== $generation) {
            return GraphPage::make($command, $arguments, $generation, $scope, [], ['status' => 'stale_snapshot', $field => []], $field);
        }
        $lease = $existingLease ?? $this->client->request(['op' => 'pin', 'generation' => $generation, 'identity' => $identity])['lease'];
        try {
            $start = $offset;
            $records = [];
            $page = null;
            $result = [];
            do {
                $page = $this->client->request(['op' => 'query_page', 'generation' => $generation, 'identity' => $identity,
                    'lease' => $lease, 'query' => $query, 'start' => $start, 'limit' => max(1, min(32, $limit - count($records))), 'diagnostic_start' => 0, 'max_bytes' => 262144]);
                if (($page['generation'] ?? null) !== $generation || ($page['start'] ?? null) !== $start
                    || ! is_int($page['total'] ?? null) || ($page['record_field'] ?? null) !== $field) {
                    throw new RuntimeException('native_basic_page_identity');
                }
                $result = TypedValue::decode($page['metadata']);
                $row = TypedValue::decode($page['records']);
                if ($limit === 0 || $row === []) {
                    break;
                }
                foreach ($row as $record) {
                    $candidate = [...$records, $record];
                    if (strlen(json_encode([$result, $candidate], JSON_THROW_ON_ERROR)) > GraphPage::MAX_BYTES - 8192) {
                        if ($records === []) {
                            $diagnostic = ['code' => 'oversized_record', 'message' => 'One native record exceeds the public page budget. Narrow the selector.'];
                            $failure = GraphPage::make($command, [], $generation, $scope, [$diagnostic], ['status' => 'unavailable', $field => []], $field);
                            $failure['diagnostics']['details'] = [$diagnostic];
                            $failure['diagnostics']['details_truncated'] = false;

                            return $failure;
                        }
                        break 2;
                    }
                    $records = $candidate;
                }
                if ($page['next'] !== null && (! is_int($page['next']) || $page['next'] <= $start)) {
                    throw new RuntimeException('native_basic_page_progress');
                }
                $start = $page['next'];
            } while ($start !== null && count($records) < $limit);
            $diagnostics = TypedValue::decode($page['diagnostics']);
            $result[$field] = $records;
            $result['execution'] = TypedValue::decode($page['execution']);
            $analysis = $result['analysis'] ?? ['mode' => 'basic', 'deep_run' => false, 'checks_complete' => $summary === []];
            unset($result['analysis']);
            $next = $offset + count($records);

            return ['v' => 1, 'cmd' => $command, 'ok' => ! in_array($result['status'], ['invalid_input', 'stale_snapshot', 'unavailable'], true),
                'snapshot' => $generation, 'scope' => $scope, 'analysis' => $analysis, 'analysis_complete' => $summary === [],
                'status' => $result['status'], 'truncated' => $next < $page['total'],
                'limits' => ['limit' => $limit, 'offset' => $offset, 'max_bytes' => GraphPage::MAX_BYTES],
                'diagnostics' => ['summary' => $summary, 'details' => $diagnostics, 'details_truncated' => $page['diagnostic_total'] > count($diagnostics)],
                'result' => $result, 'next' => $limit > 0 && $next > $offset && $next < $page['total']
                    ? ['tool' => $command, 'arguments' => [...$arguments, 'snapshot' => $generation, 'offset' => $next]] : null];
        } finally {
            if ($existingLease === null && $this->client->healthy()) {
                $this->client->request(['op' => 'release', 'lease' => $lease]);
            }
        }
    }
}
