<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog\Incremental;

use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogUpdater;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use Symfony\Component\Process\Process;

final class CatalogPatchMemoryTest extends IncrementalFixture
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_real_fragments_no_retained_overlay_chain_and_explicit_cow_export_cost(): void
    {
        $files = [];
        for ($number = 0; $number < 96; $number++) {
            $files['app/Group'.$number.'.php'] = '<?php namespace App; class Group'.$number.' { public function act() {} public function run() { $this->act(); $this->act(); } }';
        }
        $pinned = $generation = $this->prepare($files);
        $digest = $pinned->id();
        $samples = [];
        for ($iteration = 0; $iteration < 5; $iteration++) {
            $source = '<?php namespace App; class Group0 { public function act() {} public function extra'.$iteration.'() {} public function run() { $this->act(); $this->act(); } }';
            $files['app/Group0.php'] = $source;
            $before = memory_get_usage();
            $started = hrtime(true);
            $generation = $this->update($generation, $files, ['app/Group0.php' => $source]);
            $patchTime = hrtime(true) - $started;
            $this->assertSame(2, $generation->measurements['executed_calls']);
            $this->assertSame(190, $generation->measurements['reused_calls']);
            $this->assertSame(0, $generation->measurements['overlay_depth']);
            $this->assertSame(192, count($generation->operations));
            $this->assertSame($digest, $pinned->id());
            $materialize = hrtime(true);
            $view = $generation->index();
            $materialize = hrtime(true) - $materialize;
            $beforeCow = memory_get_usage();
            $id = array_key_first($view->elements);
            $view->elements[$id]['name'] .= '-reader-write';
            $cowBytes = memory_get_usage() - $beforeCow;
            $this->assertGreaterThan(0, $cowBytes);
            $this->assertNotSame($view->elements[$id]['name'], $generation->exportState()['elements'][$id]['name']);
            unset($view);
            $rss = new Process(['ps', '-o', 'rss=', '-p', (string) getmypid()]);
            $rss->mustRun();
            $samples[] = ['iteration' => $iteration, 'pid' => getmypid(), 'old_and_new_in_one_process' => true,
                'rss_kib' => (int) trim($rss->getOutput()), 'php_used' => memory_get_usage(), 'php_allocated' => memory_get_usage(true),
                'retained_used_delta' => memory_get_usage() - $before, 'patch_including_extraction_hash_guard_ns' => $patchTime,
                'materialize_validation_ns' => $materialize, 'reader_write_cow_bytes' => $cowBytes, 'composition' => array_diff_key($generation->measurements, ['trace' => true, 'ownership' => true])];
        }
        $this->assertTrue($generation->index()->checksComplete());
        $this->assertOracle($generation, $files, ['App\\Group0::act', 'App\\Group95::run']);
        $this->assertNotSame($pinned->exportState(), $generation->exportState());
        $output = getenv('W02_EVIDENCE_DIR');
        if ($output !== false && $output !== '') {
            file_put_contents($output.'/memory.json', json_encode($samples, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }
    }

    public function test_budget_refusal_preserves_old_snapshot_and_does_not_cut_semantics(): void
    {
        $files = ['app/A.php' => '<?php class A { public function run() {} }'];
        $base = $this->prepare($files);
        $id = $base->id();
        try {
            (new CatalogUpdater(transitionBudgetBytes: 1))->patch($base, [], [], $this->identity(), 'session', '1', '1');
            $this->fail('Insufficient transition budget accepted.');
        } catch (RuntimeException $error) {
            $this->assertSame('preparation_required:catalog_transition_budget', $error->getMessage());
        }
        $this->assertSame($id, $base->id());
        $this->assertOracle($base, $files, ['A']);
    }
}
