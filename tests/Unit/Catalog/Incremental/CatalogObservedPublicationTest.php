<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog\Incremental;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogContribution;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogObservedPrefix;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogUpdater;
use GracjanKubicki\ArchitectureKit\Standalone\Distribution\RuntimePlatform;
use GracjanKubicki\ArchitectureKit\Standalone\Watch\WatchBoundary;
use GracjanKubicki\ArchitectureKit\Standalone\Watch\WatchProtocol;
use GracjanKubicki\ArchitectureKit\Standalone\Watch\WatchSession;

final class CatalogObservedPublicationTest extends IncrementalFixture
{
    private function session(): WatchSession
    {
        $watch = new WatchSession(['protocol' => WatchProtocol::PROTOCOL, 'runtime_version' => WatchProtocol::VERSION,
            'platform' => 'macos-arm64', 'target' => RuntimePlatform::TARGETS['macos-arm64'], 'capabilities' => WatchProtocol::CAPABILITIES,
            'session' => str_repeat('a', 64), 'sequence' => '1', 'health' => 'unverified', 'reason' => 'bootstrap_required'],
            'macos-arm64', new WatchBoundary(['/fixture/app']));
        $watch->accept($this->frame($watch, '2', 'watch-ready'));
        $this->assertTrue($watch->acknowledgeReconcile($watch->id, '2'));

        return $watch;
    }

    private function frame(WatchSession $watch, string $sequence, string $kind): string
    {
        return json_encode(['protocol' => WatchProtocol::PROTOCOL, 'session' => $watch->id, 'sequence' => $sequence, 'kind' => $kind,
            'paths_encoding' => 'base64-os-bytes', 'paths' => $kind === 'changes' ? [base64_encode('/fixture/app/A.php')] : [],
            'health' => $kind === 'watch-ready' ? 'unverified' : 'observing',
            'reason' => $kind === 'watch-ready' ? 'bootstrap_required' : null, 'rescan_required' => $kind === 'watch-ready'], JSON_THROW_ON_ERROR)."\n";
    }

    public function test_observed_publication_checks_changed_bytes_and_continuity_without_full_scan_claim(): void
    {
        $watch = $this->session();
        $files = ['app/A.php' => '<?php class A {}', 'app/Independent.php' => '<?php class Independent {}'];
        $updater = new CatalogUpdater;
        $base = $updater->prepare($this->collect($files), $this->identity(), $watch->id, $watch->sequence);
        $files['app/A.php'] = '<?php class A { public function changed() {} }';
        $watch->accept($this->frame($watch, '3', 'changes'));
        $patch = $updater->patch($base, $this->collect(['app/A.php' => $files['app/A.php']]), [], $this->identity(), $watch->id, '3', '3');
        $prefix = new CatalogObservedPrefix($watch, '3');
        $read = [];
        $result = $patch->applyObserved($base, $prefix, function ($path) use (&$read, $files) {
            $read[] = $path;

            return $files[$path] ?? null;
        });
        $this->assertSame(['app/A.php'], $read);
        $this->assertSame('observed_prefix', $result['basis']);
        $this->assertSame('last_confirmed', $result['unchanged_basis']);
        $this->assertOracle($result['generation'], $files, ['A', 'Independent']);
        $watch->lose('overflow');
        $this->expectExceptionMessage('observation_not_continuous');
        $patch->applyObserved($base, $prefix, fn ($path) => $files[$path] ?? null);
    }

    public function test_new_event_during_verification_refuses_the_old_prefix(): void
    {
        $watch = $this->session();
        $files = ['app/A.php' => '<?php class A {}'];
        $base = (new CatalogUpdater)->prepare($this->collect($files), $this->identity(), $watch->id, '2');
        $files['app/A.php'] = '<?php class Changed {}';
        $watch->accept($this->frame($watch, '3', 'changes'));
        $patch = (new CatalogUpdater)->patch($base, $this->collect($files), [], $this->identity(), $watch->id, '3', '3');
        $this->expectExceptionMessage('observation_not_continuous');
        $patch->applyObserved($base, new CatalogObservedPrefix($watch, '3'), function ($path) use ($files, $watch) {
            $watch->accept($this->frame($watch, '4', 'changes'));

            return $files[$path] ?? null;
        });
    }

    public function test_deep_extraction_is_rejected_at_incremental_contribution_boundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CatalogContribution::collect(
            new FileContext('app/A.php', '<?php class A {}'),
            new ProjectGraphBuilder(catalog: true, mode: AnalysisMode::Deep));
    }
}
