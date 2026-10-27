<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog\Search;

use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\Search\SearchState;
use GracjanKubicki\ArchitectureKit\Catalog\Search\SearchStateCodec;
use GracjanKubicki\ArchitectureKit\Context\GraphSearch;

final class SearchRetentionTest extends SearchFixture
{
    public function test_flat_compaction_and_old_lease_have_bounded_ownership_after_churn(): void
    {
        $files = [];
        for ($i = 0; $i < 24; $i++) {
            $files['app/Item'.$i.'.php'] = '<?php namespace W03; class Item'.$i.' { function dispatch() {} }';
        }
        $base = $this->prepare($files);
        $started = hrtime(true);
        $used = memory_get_usage();
        $state = SearchState::fromGeneration($base);
        $build = ['ns' => hrtime(true) - $started, 'used_delta' => memory_get_usage() - $used];
        $leased = $base->index();
        $leased->attachSearchState($state);
        $oldAnswer = (new GraphSearch($leased))->find('Item0');
        $initial = $state->measurements();
        $reference = \WeakReference::create($state);
        $samples = [];
        for ($cycle = 1; $cycle <= 18; $cycle++) {
            $oldPath = $cycle === 1 ? 'app/Item0.php' : 'app/Moved'.($cycle - 1).'.php';
            unset($files[$oldPath]);
            $path = 'app/Moved'.$cycle.'.php';
            $files[$path] = '<?php namespace W03; class Changed'.$cycle.' { function dispatch() {} }';
            $patch = $this->patch($base, $files, [$path => $files[$path]], [$oldPath]);
            $started = hrtime(true);
            $before = memory_get_usage();
            $next = $state->apply($patch);
            $samples[] = ['cycle' => $cycle, 'patch_ns' => hrtime(true) - $started,
                'transition_used' => memory_get_usage(), 'transition_delta' => memory_get_usage() - $before,
                'allocated' => memory_get_usage(true), 'rss_kib' => $this->rss(), 'counts' => $next->measurements()];
            $this->assertSame(0, $next->measurements()['overlay_depth']);
            $this->assertSame(0, $next->measurements()['tombstones']);
            $this->assertSame($initial['records'], $next->measurements()['records']);
            $this->assertLessThanOrEqual($initial['values'] + 2, $next->measurements()['values']);
            $base = $patch->target;
            $state = $next;
            unset($next, $patch);
            $index = $base->index();
            $index->attachSearchState($state);
            $this->assertSame(0, (new GraphSearch($index))->find($oldPath)['match_count']);
            if ($cycle % 6 === 0) {
                $oracle = new CatalogIndex(array_map(fn ($source) => $source->facts, $this->collect($files)));
                $this->parity($index, $oracle, [['Item'], ['Changed'], ['dispatch'], ['a']]);
                $this->assertSame(SearchState::fromGeneration($base)->export(), $state->export());
            }
            $this->assertSame($oldAnswer, (new GraphSearch($leased))->find('Item0'));
        }
        $this->assertNotNull($reference->get());
        unset($leased);
        gc_collect_cycles();
        $this->assertNull($reference->get());
        $bytes = SearchStateCodec::encode($state);
        $started = hrtime(true);
        $restored = SearchStateCodec::decode($bytes, $index->elements, $state->generation, $state->identity);
        $restoreNs = hrtime(true) - $started;
        $this->assertSame($state->export(), $restored->export());
        $this->evidence(['fixture' => '24 synthetic PHP sources, real Basic parser/catalog records; 18 real W02 move+rename patches; compaction every patch',
            'build' => $build, 'samples' => $samples, 'restore_ns' => $restoreNs, 'encoded_bytes' => strlen($bytes),
            'final_used' => memory_get_usage(), 'final_allocated' => memory_get_usage(true), 'peak' => memory_get_peak_usage(), 'rss_kib' => $this->rss()]);
    }

    private function rss(): int
    {
        $lines = [];
        exec('ps -o rss= -p '.getmypid(), $lines);

        return (int) trim(implode('', $lines));
    }
}
