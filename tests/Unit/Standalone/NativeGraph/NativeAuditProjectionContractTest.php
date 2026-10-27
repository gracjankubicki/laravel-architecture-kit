<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\NativeGraph;

use GracjanKubicki\ArchitectureKit\Analysis\BasicFactsOptions;
use GracjanKubicki\ArchitectureKit\Analysis\BasicSource;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntry;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogDiagnostic;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\CaptureRecord;
use GracjanKubicki\ArchitectureKit\Standalone\Capture\TypedValue;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeAuditProjection;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeGraphClient;
use GracjanKubicki\ArchitectureKit\Standalone\NativeGraph\NativeProductScope;

final class NativeAuditProjectionContractTest extends NativeBasicFixture
{
    public function test_per_owner_diagnostics_are_bounded_and_excluded_helpers_do_not_affect_completeness(): void
    {
        $options = new BasicFactsOptions(false, true, false);
        $records = [];
        foreach (['app/Bad.php' => 'parse_error', 'app/Good.php' => null, 'app/Helper.php' => 'duplicate_symbol'] as $path => $code) {
            $diagnostics = $code === null ? [] : [new CatalogDiagnostic($code, 'local '.$code, 1)];
            $facts = new CatalogFacts($path, [], [], $diagnostics);
            $records[] = CaptureRecord::create(count($records), 'php', BasicSource::plain(new SourceInput($path, '', '8.3')), $options,
                new FileGraphEntry([], [], catalog: $facts, syntaxError: $code === 'parse_error' ? 'bad source' : null));
        }
        $scope = array_map(static fn ($r) => array_intersect_key($r, array_flip(['owner', 'sha256', 'kind', 'php_version'])), $records);
        $ingest = $this->client->request(['op' => 'begin', 'identity' => $this->identity, 'options' => $options->toArray(), 'scope' => $scope])['ingest'];
        foreach ($records as $record) {
            $this->client->request(['op' => 'append', 'ingest' => $ingest, 'record' => $record]);
        }
        $generation = $this->client->request(['op' => 'commit', 'ingest' => $ingest])['generation'];
        $lease = $this->client->request(['op' => 'pin', 'generation' => $generation, 'identity' => $this->identity])['lease'];
        $page = $this->client->request(['op' => 'audit_page', 'generation' => $generation, 'identity' => $this->identity, 'lease' => $lease, 'start' => 0, 'limit' => 32, 'diagnostics_only' => true]);
        self::assertSame('native-audit-projection-v1', $page['schema']);
        $rows = TypedValue::decode($page['records']);
        self::assertCount(3, $rows);
        self::assertSame('bad source', $rows[0]['syntax_error']);
        self::assertFalse($rows[0]['checks_complete']);
        self::assertNull($rows[1]['syntax_error']);
        self::assertTrue($rows[1]['checks_complete']);
        foreach ($rows as $row) {
            self::assertArrayNotHasKey('catalog', $row);
            self::assertArrayNotHasKey('symbols', $row);
        }
        $projection = new NativeAuditProjection($this->client);
        $all = $projection->diagnostics($generation, $this->identity, $lease, new NativeProductScope(AuditScope::package(['app']), []), 67108864);
        self::assertFalse($all['checks_complete']);
        self::assertSame(['app/Bad.php', 'app/Helper.php'], array_column($all['diagnostics'], 'path'));
        $filtered = $projection->diagnostics($generation, $this->identity, $lease, new NativeProductScope(AuditScope::package(['app']), ['app/Bad.php', 'app/Helper.php']), 67108864);
        self::assertTrue($filtered['checks_complete']);
        self::assertSame([], $filtered['diagnostics']);
    }

    public function test_null_catalog_with_syntax_failure_is_not_reported_complete(): void
    {
        $options = new BasicFactsOptions(false, false, false);
        $record = CaptureRecord::create(0, 'php', BasicSource::plain(new SourceInput('app/Bad.php', '', '8.3')), $options, new FileGraphEntry([], [], syntaxError: 'bad source'));
        $scope = [array_intersect_key($record, array_flip(['owner', 'sha256', 'kind', 'php_version']))];
        $ingest = $this->client->request(['op' => 'begin', 'identity' => $this->identity, 'options' => $options->toArray(), 'scope' => $scope])['ingest'];
        $this->client->request(['op' => 'append', 'ingest' => $ingest, 'record' => $record]);
        $generation = $this->client->request(['op' => 'commit', 'ingest' => $ingest])['generation'];
        $lease = $this->client->request(['op' => 'pin', 'generation' => $generation, 'identity' => $this->identity])['lease'];
        $result = (new NativeAuditProjection($this->client))->diagnostics($generation, $this->identity, $lease, new NativeProductScope(AuditScope::package(['app']), []), 67108864);
        self::assertSame([], $result['diagnostics']);
        self::assertFalse($result['checks_complete']);
    }

    public function test_r1_runtime_missing_projection_schema_is_explicitly_unsupported(): void
    {
        $binary = getenv('ARCHITECTURE_KIT_TEST_R1_RUNTIME');
        if (! is_string($binary) || ! is_file($binary)) {
            self::markTestSkipped('Explicit frozen r1 runtime required for compatibility refusal.');
        }
        $old = new NativeGraphClient($binary, 268435456, 536870912);
        try {
            $ingest = $old->request(['op' => 'begin', 'identity' => $this->identity, 'options' => (new BasicFactsOptions)->toArray(), 'scope' => []])['ingest'];
            $generation = $old->request(['op' => 'commit', 'ingest' => $ingest])['generation'];
            $this->expectExceptionMessage('native_audit_projection_unsupported');
            (new NativeAuditProjection($old))->load($generation, $this->identity, 67108864);
        } finally {
            $old->close();
        }
    }
}
