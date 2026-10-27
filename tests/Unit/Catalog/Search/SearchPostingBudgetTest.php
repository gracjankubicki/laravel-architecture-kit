<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog\Search;

use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\Search\SearchState;
use GracjanKubicki\ArchitectureKit\Context\GraphSearch;

final class SearchPostingBudgetTest extends SearchFixture
{
    public function test_budget_boundaries_do_not_cap_results_and_fallback_is_explicit(): void
    {
        $oracle = $this->index();
        $required = SearchState::build($oracle->elements)->measurements()['postings'];
        foreach ([0, 1, $required - 1, $required, $required + 1] as $budget) {
            $index = clone $oracle;
            $state = SearchState::build($index->elements, postingBudget: $budget);
            $index->attachSearchState($state);
            $this->assertLessThanOrEqual($budget, $state->measurements()['postings']);
            $this->parity($index, $oracle, [['a'], ['ab'], ['marker'], ['only'], ['class'], ['app/'], ['absent']]);
            $search = new GraphSearch($index);
            $result = $search->find('marker');
            $this->assertSame(1, $result['match_count']);
            $mode = $search->execution();
            $this->assertSame($budget < $required ? 'exact_dictionary_scan' : 'indexed', $mode['mode']);
            $this->assertSame($budget < $required ? 'posting_budget' : null, $mode['reason']);
            if ($budget < $required) {
                $this->assertNotSame([...$mode, 'reason' => null], $mode);
            }
            $this->evidence(['budget' => $budget, 'required' => $required, 'measurements' => $state->measurements(), 'execution' => $mode]);
        }
    }

    public function test_large_popular_posting_and_patch_fallback_never_truncate_count(): void
    {
        $files = [];
        for ($i = 0; $i < 30; $i++) {
            $files['app/File'.$i.'.php'] = '<?php namespace Fixture; class Popular'.$i.' {}';
        }
        $base = $this->prepare($files);
        $state = SearchState::fromGeneration($base, 2);
        $files['app/File0.php'] = '<?php namespace Fixture; class PopularChanged {}';
        $patch = $this->patch($base, $files, ['app/File0.php' => $files['app/File0.php']]);
        $state = $state->apply($patch);
        $index = $patch->target->index();
        $index->attachSearchState($state);
        $oracle = new CatalogIndex(array_map(fn ($source) => $source->facts, $this->collect($files)));
        $this->parity($index, $oracle, [['Popular'], ['p'], ['ar'], ['PopularChanged']]);
        $this->pageParity($index, $oracle, [['Popular'], ['p']]);
        $this->assertSame(30, (new GraphSearch($index))->find('Popular')['match_count']);
    }

    public function test_postings_budget_can_recover_after_last_large_owner_is_deleted(): void
    {
        $files = ['app/Long.php' => '<?php class Abcdefghijklmnopqrstuvwxyz {}', 'app/A.php' => '<?php class A {}'];
        $base = $this->prepare($files);
        $minimum = $this->prepare(['app/A.php' => $files['app/A.php']]);
        $budget = SearchState::fromGeneration($minimum)->measurements()['postings'];
        $state = SearchState::fromGeneration($base, $budget);
        $this->assertSame('posting_budget', $state->execution('class')['reason']);
        unset($files['app/Long.php']);
        $patch = $this->patch($base, $files, [], ['app/Long.php']);
        $state = $state->apply($patch);
        $this->assertNull($state->execution('class')['reason']);
        $this->assertSame(SearchState::fromGeneration($patch->target, $budget)->export(), $state->export());
    }
}
