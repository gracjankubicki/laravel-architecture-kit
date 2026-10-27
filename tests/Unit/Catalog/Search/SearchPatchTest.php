<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog\Search;

use GracjanKubicki\ArchitectureKit\Catalog\CatalogElement;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogContribution;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogPatch;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogUpdater;
use GracjanKubicki\ArchitectureKit\Catalog\Search\SearchState;
use GracjanKubicki\ArchitectureKit\Catalog\Search\SearchStateCodec;
use GracjanKubicki\ArchitectureKit\Context\GraphSearch;
use InvalidArgumentException;

final class SearchPatchTest extends SearchFixture
{
    public function test_real_w02_edit_add_delete_move_and_reject_wrong_base(): void
    {
        $files = ['app/Actions/Invoice.php' => '<?php namespace App; class Invoice { function send() {} }',
            'tests/Probe.php' => '<?php namespace Tests; class Probe { function check(\\App\\Invoice $invoice) { $invoice->send(); } }'];
        $generation = $this->prepare($files);
        $state = SearchState::fromGeneration($generation);
        $old = $generation->index();
        $old->attachSearchState($state);
        $oldAnswer = (new GraphSearch($old))->find('Invoice');
        $queries = [['Invoice'], ['Receipt'], ['send'], ['renamed'], ['app/Actions'], ['tests'], ['a'], ['ab'], ['class'], ['Receipt', 'class', 'Modules']];
        foreach (['edit', 'add', 'delete', 'move'] as $operation) {
            $deleted = [];
            $changed = [];
            if ($operation === 'edit') {
                $changed = ['app/Actions/Invoice.php' => '<?php namespace App; class Receipt { function renamed() {} }'];
            } elseif ($operation === 'add') {
                $changed = ['app/Extra.php' => '<?php namespace App; function extra() {}'];
            } elseif ($operation === 'delete') {
                $deleted = ['app/Extra.php'];
            } else {
                $changed = ['Modules/Receipt.php' => $files['app/Actions/Invoice.php']];
                $deleted = ['app/Actions/Invoice.php'];
            }
            foreach ($deleted as $path) {
                unset($files[$path]);
            }
            $files = [...$files, ...$changed];
            $patch = $this->patch($generation, $files, $changed, $deleted);
            $next = $state->apply($patch);
            // Publication guards/sources remain W02/W04 responsibilities.
            $generation = $patch->apply($generation, 'w03-session', fn ($path) => $files[$path] ?? null);
            $index = $generation->index();
            $index->attachSearchState($next);
            $oracle = new CatalogIndex(array_map(fn ($source) => $source->facts, $this->collect($files)));
            $this->parity($index, $oracle, $queries);
            $this->pageParity($index, $oracle, $queries);
            $this->assertSame(SearchState::fromGeneration($generation)->export(), $next->export());
            $this->evidence(['operation' => $operation, 'files' => $files, 'base' => $patch->baseGeneration, 'target' => $generation->id(), 'delta' => $patch->changes['elements'], 'measurements' => $patch->measurements, 'search' => $next->measurements()]);
            try {
                $next->apply($patch);
                $this->fail('A patch for the previous generation must be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('search_patch_base_or_target', $exception->getMessage());
            }
            $state = $next;
        }
        $this->assertSame($oldAnswer, (new GraphSearch($old))->find('Invoice'));
        $this->assertSame(0, (new GraphSearch($generation->index()))->find('app/Actions/Invoice.php')['match_count']);
    }

    public function test_real_shared_resource_owner_removal_preserves_independent_value(): void
    {
        $updater = new CatalogUpdater;
        $shared = new CatalogElement('shared', 'common-queue', 'queue', 1, 1, 0);
        $independent = new CatalogElement('independent', 'common-queue', 'queue', 1, 1, 0);
        $sources = [new CatalogContribution(new CatalogFacts('app/First.php', [$shared]), hash('sha256', 'one')),
            new CatalogContribution(new CatalogFacts('app/Second.php', [$shared]), hash('sha256', 'two')),
            new CatalogContribution(new CatalogFacts('app/Third.php', [$independent]), hash('sha256', 'three'))];
        $files = ['app/First.php' => 'one', 'app/Second.php' => 'two', 'app/Third.php' => 'three'];
        $base = $updater->prepare($sources, $this->identity(), 'w03-session', contents: fn ($path) => $files[$path] ?? null);
        $state = SearchState::fromGeneration($base);
        foreach (['app/First.php', 'app/Second.php'] as $path) {
            unset($files[$path]);
            $patch = $this->patch($base, $files, [], [$path]);
            $state = $state->apply($patch);
            $base = $patch->target;
            $index = $base->index();
            $index->attachSearchState($state);
            $remaining = array_values(array_filter($sources, fn ($source) => isset($files[$source->facts->path])));
            $oracle = new CatalogIndex(array_map(fn ($source) => $source->facts, $remaining));
            $this->parity($index, $oracle, [['common-queue'], ['app/First.php'], ['app/Second.php'], ['common-queue', null, 'app/Third.php']]);
            $this->assertSame(0, (new GraphSearch($index))->find($path)['match_count']);
            $this->assertContains('independent', array_column((new GraphSearch($index))->find('common-queue')['candidates'], 'id'));
            $this->assertSame(SearchState::fromGeneration($base)->export(), $state->export());
        }
        $this->assertSame(['independent'], array_column((new GraphSearch($index))->find('common-queue')['candidates'], 'id'));
    }

    public function test_real_w02_role_metadata_and_kind_upserts_change_search_values(): void
    {
        $updater = new CatalogUpdater;
        $facts = new CatalogFacts('app/Actions/Record.php', [new CatalogElement('record', 'Stable', 'class', 1, 1, 0,
            roles: ['controller'], metadata: ['name' => 'old-meta', 'uri' => '/old-uri', 'table' => 'old-table', 'route_name' => 'old-route'])]);
        $base = $updater->prepare([new CatalogContribution($facts, hash('sha256', 'before'))], $this->identity(), 'w03-session', contents: fn ($path) => 'before');
        $state = SearchState::fromGeneration($base);
        $changed = new CatalogFacts('app/Actions/Record.php', [new CatalogElement('record', 'Stable', 'function', 1, 1, 0,
            roles: ['listener'], metadata: ['name' => 'new-meta', 'uri' => '/new-uri', 'table' => 'new-table', 'route_name' => 'new-route'])]);
        $patch = $updater->patch($base, [new CatalogContribution($changed, hash('sha256', 'after'))], [], $this->identity(), 'w03-session', '1', '1', fn ($path) => 'after');
        $next = $state->apply($patch);
        $index = $patch->target->index();
        $index->attachSearchState($next);
        $oracle = new CatalogIndex([$changed]);
        $queries = [['old-meta'], ['old-uri'], ['old-table'], ['old-route'], ['new-meta'], ['new-uri'], ['new-table'], ['new-route'], ['Stable', 'controller'], ['Stable', 'listener'], ['Stable', 'function']];
        $this->parity($index, $oracle, $queries);
        $this->pageParity($index, $oracle, $queries);
        $this->assertSame(0, (new GraphSearch($index))->find('old-meta')['match_count']);
        $this->assertSame(['record'], array_column((new GraphSearch($index))->find('new-meta')['candidates'], 'id'));
    }

    public function test_restore_missing_corrupt_foreign_and_recomputed_digest_semantic_corruption(): void
    {
        $generation = $this->prepare(['app/Name.php' => '<?php namespace App; class Name { function go() {} }']);
        $state = SearchState::fromGeneration($generation);
        $elements = $generation->exportState()['elements'];
        $bytes = SearchStateCodec::encode($state);
        $restored = SearchStateCodec::load($bytes, $elements, $generation->id(), $generation->identity->digest());
        $this->assertSame('restored', $restored['status']);
        $this->assertSame($state->export(), $restored['state']->export());
        $variants = [null, 'garbage', '<?php throw new Exception("executed");', str_replace('"digest":"', '"digest":"0', $bytes)];
        foreach (['schema', 'source_digest', 'generation', 'identity', 'postings', 'values', 'records', 'grams'] as $field) {
            $envelope = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
            $envelope['data'][$field] = match ($field) {
                'schema' => 2, 'postings' => 999, 'values', 'records', 'grams' => [], default => 'foreign',
            };
            $envelope['digest'] = hash('sha256', json_encode($envelope['data'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $variants[] = json_encode($envelope, JSON_THROW_ON_ERROR);
        }
        foreach ($variants as $variant) {
            $fallback = SearchStateCodec::load($variant, $elements, $generation->id(), $generation->identity->digest());
            $this->assertSame('exact_fallback', $fallback['status']);
            $this->assertNotEmpty($fallback['reason']);
            $index = $generation->index();
            $index->attachSearchState($fallback['state']);
            $this->parity($index, $generation->index(), [['Name'], ['go'], ['a'], ['absent']]);
            $this->evidence(['reason' => $fallback['reason'], 'execution' => $fallback['state']->execution('Name')]);
        }
        $foreign = SearchStateCodec::load($bytes, $elements, 'other', $generation->identity->digest());
        $this->assertSame('exact_fallback', $foreign['status']);
        $this->assertCount(8, $generation->index()->exportState());
    }

    public function test_bad_patch_delta_and_catalog_binding_never_publish_partial_index(): void
    {
        $files = ['app/A.php' => '<?php class A {}'];
        $base = $this->prepare($files);
        $state = SearchState::fromGeneration($base);
        $files['app/A.php'] = '<?php class B {}';
        $patch = $this->patch($base, $files, $files);
        $bad = new CatalogPatch($patch->baseGeneration, $patch->identity, $patch->session, $patch->firstSequence, $patch->lastSequence, $patch->inputHashes, $patch->target, ['elements' => ['removed' => [], 'upserted' => []]], $patch->deleted, $patch->changedHashes, $patch->measurements);
        try {
            $state->apply($bad);
            $this->fail('Missing real delta must be detected.');
        } catch (InvalidArgumentException) {
            $this->assertSame(SearchState::fromGeneration($base)->export(), $state->export());
        }
        $this->expectExceptionMessage('search_state_catalog_mismatch');
        $patch->target->index()->attachSearchState($state);
    }
}
