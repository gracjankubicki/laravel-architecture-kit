<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog\Search;

use GracjanKubicki\ArchitectureKit\Catalog\CatalogElement;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogContribution;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogSequence;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogUpdater;
use GracjanKubicki\ArchitectureKit\Catalog\Search\SearchState;
use GracjanKubicki\ArchitectureKit\Catalog\Search\SearchStateCodec;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class SearchNumericIdentityTest extends SearchFixture
{
    /** @return iterable<string, array{string}> */
    public static function ids(): iterable
    {
        foreach (['0', '123', '-1', '00', '1.0'] as $id) {
            yield 'id-'.$id => [$id];
        }
    }

    private function source(string $id, string $name, string $path): CatalogContribution
    {
        return new CatalogContribution(new CatalogFacts($path, [new CatalogElement($id, $name, 'queue', 1, 1, 0)]), hash('sha256', $name));
    }

    #[DataProvider('ids')]
    public function test_numeric_textual_ids_query_cache_fallback_and_real_w02_patches(string $id): void
    {
        $first = $this->source($id, 'needle', 'app/A.php');
        $original = new CatalogIndex([$first->facts]);
        $this->parity($original, $original, [['needle'], [$id], ['n'], ['ed']]);
        $this->assertSame($id, $original->elements[$id]['id']);
        foreach ([null, 'corrupt'] as $bytes) {
            $load = SearchStateCodec::load($bytes, $original->elements, 'legacy', 'legacy');
            $this->assertSame('exact_fallback', $load['status']);
            $this->assertNotEmpty($load['reason']);
            $fallback = clone $original;
            $fallback->attachSearchState($load['state']);
            $this->parity($fallback, $original, [['needle'], [$id]]);
            $this->evidence(['phase' => 'cache-fallback', 'reason' => $load['reason'], 'source_id' => $id]);
        }
        $updater = new CatalogUpdater;
        $base = $updater->prepare([], $this->identity(), 'w03-session');
        $state = SearchState::fromGeneration($base);
        $sources = [];
        foreach ([['add', $first, []], ['update', $this->source($id, 'after', 'app/A.php'), []],
            ['share', $this->source($id, 'after', 'app/B.php'), []], ['delete-first', null, ['app/A.php']], ['delete-last', null, ['app/B.php']]] as [$phase, $source, $deleted]) {
            foreach ($deleted as $path) {
                unset($sources[$path]);
            }
            if ($source !== null) {
                $sources[$source->facts->path] = $source;
            }
            $sequence = CatalogSequence::next($base->sequence);
            $reader = fn ($path) => isset($sources[$path]) ? $sources[$path]->facts->elements[0]->name : null;
            $patch = $updater->patch($base, $source === null ? [] : [$source], $deleted, $this->identity(), 'w03-session', $sequence, $sequence, $reader);
            $next = $state->apply($patch);
            $base = $patch->apply($base, 'w03-session', $reader);
            $index = $base->index();
            $index->attachSearchState($next);
            $ordered = $sources;
            ksort($ordered);
            $oracle = new CatalogIndex(array_map(fn ($entry) => $entry->facts, array_values($ordered)));
            $this->parity($index, $oracle, [['needle'], ['after'], [$id], ['a'], ['app/A.php'], ['app/B.php']]);
            $this->pageParity($index, $oracle, [['after'], [$id]]);
            $bytes = SearchStateCodec::encode($next);
            $state = SearchStateCodec::decode($bytes, $index->elements, $base->id(), $base->identity->digest());
            $this->assertSame($bytes, SearchStateCodec::encode($state));
            $this->assertSame(SearchState::fromGeneration($base)->export(), $state->export());
            $this->evidence(['phase' => $phase, 'source_id' => $id, 'delta' => $patch->changes['elements'], 'state' => $state->export()]);
            if ($phase === 'add') {
                $invalid = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
                foreach ($invalid['data']['values'] as &$owners) {
                    if ($owners === [$id]) {
                        $owners = [(int) $id];
                        break;
                    }
                }
                unset($owners);
                $invalid['digest'] = hash('sha256', json_encode($invalid['data'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                try {
                    SearchStateCodec::decode(json_encode($invalid, JSON_THROW_ON_ERROR), $index->elements, $base->id(), $base->identity->digest());
                    $this->fail('Integer owner identity must not replace the original string.');
                } catch (InvalidArgumentException $exception) {
                    $this->assertSame('search_state_owner_reference', $exception->getMessage());
                }
            }
        }
    }

    /** @param array<string, mixed> $data */
    protected function evidence(array $data): void
    {
        $directory = getenv('W03_DELTA_EVIDENCE');
        if ($directory !== false && $directory !== '') {
            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($directory.'/numeric.ndjson', json_encode(['test' => $this->nameWithDataSet(), ...$data], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
        }
    }
}
