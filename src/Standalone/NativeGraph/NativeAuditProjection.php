<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone\NativeGraph;

use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\DependencyEdge;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectSymbol;
use GracjanKubicki\ArchitectureKit\Audit\TestReachability\TestInvocation;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogDiagnostic;
use GracjanKubicki\ArchitectureKit\Impact\ImpactFacts;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\TypedValue;
use GracjanKubicki\ArchitectureKit\Standalone\GraphAdmissionRefused;
use GracjanKubicki\ArchitectureKit\Support\MemoryLimit;
use RuntimeException;

/** Temporary structural input for PHP audit rules. No catalog, search, calls or product state. */
final readonly class NativeAuditProjection
{
    public function __construct(private NativeGraphClient $client) {}

    /** Bounded per-owner diagnostic DTOs only; never hydrate CatalogFacts.
     * @param array<string, string> $identity
     * @return array{diagnostics: list<array<string, mixed>>, checks_complete: bool} */
    public function diagnostics(string $generation, array $identity, string $lease, NativeProductScope $scope, int $budget): array
    {
        $diagnostics = [];
        $complete = true;
        $start = 0;
        $baseline = memory_get_usage(true);
        do {
            $remaining = $budget - (memory_get_usage(true) - $baseline);
            if ($remaining < 8192 * NativeGraphClient::DECODE_MULTIPLIER) {
                throw new RuntimeException('native_audit_projection_budget');
            }
            $page = $this->client->request(['op' => 'audit_page', 'generation' => $generation, 'identity' => $identity, 'lease' => $lease,
                'start' => $start, 'limit' => 32, 'diagnostics_only' => true, 'max_bytes' => min(262144, intdiv($remaining, NativeGraphClient::DECODE_MULTIPLIER))]);
            self::validatePage($page, $generation, $start);
            foreach (TypedValue::decode($page['records']) as $row) {
                self::validateRow($row);
                if ($scope->allows($row['owner'])) {
                    $complete = $complete && $row['checks_complete'];
                    foreach ($row['diagnostics'] as $diagnostic) {
                        $diagnostics[] = [...$diagnostic, 'path' => $row['owner']];
                    }
                }
            }
            if (memory_get_usage(true) - $baseline > $budget) {
                throw new RuntimeException('native_audit_projection_budget');
            }
            if ($page['next'] !== null && $page['next'] <= $start) {
                throw new RuntimeException('native_diagnostic_page_progress');
            }
            $start = $page['next'];
        } while ($start !== null);

        return ['diagnostics' => $diagnostics, 'checks_complete' => $complete];
    }

    /** @param array<string, mixed> $page */
    private static function validatePage(array $page, string $generation, int $start): void
    {
        if (($page['schema'] ?? null) !== 'native-audit-projection-v1') {
            throw new RuntimeException('native_audit_projection_unsupported');
        }
        if (($page['generation'] ?? null) !== $generation || ($page['start'] ?? null) !== $start
            || ! is_int($page['total'] ?? null) || ! is_array($page['records'] ?? null) || ! array_key_exists('next', $page)
            || ($page['next'] !== null && ! is_int($page['next']))) {
            throw new RuntimeException('native_audit_page_identity');
        }
    }

    /** @param array<string, mixed> $row */
    private static function validateRow(array $row): void
    {
        if (! is_string($row['owner'] ?? null) || ! is_array($row['diagnostics'] ?? null) || ! array_is_list($row['diagnostics'])
            || ! is_bool($row['checks_complete'] ?? null) || ! array_key_exists('syntax_error', $row)
            || ($row['syntax_error'] !== null && ! is_string($row['syntax_error']))) {
            throw new RuntimeException('native_audit_projection_shape');
        }
        $complete = $row['syntax_error'] === null;
        foreach ($row['diagnostics'] as $diagnostic) {
            try {
                CatalogDiagnostic::fromArray($diagnostic);
            } catch (\Throwable $error) {
                throw new RuntimeException('native_audit_projection_shape', previous: $error);
            }
            $complete = $complete && ! preg_match('/limit|parse|changed_inputs/', $diagnostic['code']);
        }
        if ($complete !== $row['checks_complete']) {
            throw new RuntimeException('native_audit_projection_shape');
        }
    }

    /** @param array<string, string> $identity */
    public function load(string $generation, array $identity, int $budget, bool $assessment = false, ?string $existingLease = null): ProjectGraphSnapshot
    {
        $lease = $existingLease ?? $this->client->request(['op' => 'pin', 'generation' => $generation, 'identity' => $identity])['lease'];
        $baseline = memory_get_usage(true);
        $symbols = $edges = $tests = $impacts = [];
        $start = $count = 0;
        try {
            do {
                if ($budget - (memory_get_usage(true) - $baseline) < 8192 * NativeGraphClient::DECODE_MULTIPLIER) {
                    throw new RuntimeException('native_audit_projection_budget');
                }
                $page = $this->client->request(['op' => 'audit_page', 'generation' => $generation, 'identity' => $identity, 'lease' => $lease, 'start' => $start, 'limit' => 1, 'impact' => $assessment, 'max_bytes' => min(NativeGraphClient::FRAME_BYTES, max(8192, intdiv($budget - (memory_get_usage(true) - $baseline), NativeGraphClient::DECODE_MULTIPLIER)))]);
                self::validatePage($page, $generation, $start);
                foreach (TypedValue::decode($page['records']) as $row) {
                    self::validateRow($row);
                    $path = $row['owner'];
                    foreach ($row['symbols'] as $symbol) {
                        $symbols[] = new ProjectSymbol($symbol[0], $path, $symbol[1], $symbol[2], $symbol[3], $symbol[4], $symbol[5]);
                    }
                    foreach ($row['edges'] as $edge) {
                        $edges[] = new DependencyEdge($edge[0], $edge[1], $path, $edge[2], $edge[3], $edge[4]);
                    }
                    foreach ($row['test_invocations'] as $test) {
                        $tests[] = TestInvocation::fromArray($path, $test);
                    }
                    if ($assessment && $row['impact'] !== null) {
                        $impacts[] = ImpactFacts::fromArray($path, $row['impact']);
                    }
                    $count++;
                }
                if (memory_get_usage(true) - $baseline > $budget) {
                    throw new GraphAdmissionRefused('native_audit_projection_budget', ['allocated_delta' => memory_get_usage(true) - $baseline, 'budget' => $budget]);
                }
                if ($page['next'] !== null && $page['next'] <= $start) {
                    throw new RuntimeException('native_audit_page_progress');
                }
                $start = $page['next'];
            } while ($start !== null);
            if ($count !== $page['total']) {
                throw new RuntimeException('native_audit_page_coverage');
            }
            $indexReserve = count($symbols) * 512;
            $phpLimit = MemoryLimit::bytes();
            if (memory_get_usage(true) - $baseline + $indexReserve > $budget || $phpLimit !== null && memory_get_usage(true) + $indexReserve + 262144 > $phpLimit) {
                throw new RuntimeException('native_audit_projection_budget');
            }

            return new ProjectGraphSnapshot($symbols, $edges, $tests, $impacts);
        } finally {
            if ($existingLease === null && $this->client->healthy()) {
                $this->client->request(['op' => 'release', 'lease' => $lease]);
            }
        }
    }
}
