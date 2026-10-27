<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog\Incremental;

use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\ExpectationFailedException;

final class IncrementalCatalogComparatorTest extends IncrementalFixture
{
    public function test_full_comparator_rejects_each_field_and_equal_count_record_mutations(): void
    {
        $base = $this->prepare(['app/Known.php' => '<?php class Known { public function exists() {} }',
            'app/Caller.php' => '<?php function caller(Known $k) { $k->missing(); $k->exists(); }']);
        $state = $base->exportState();
        $this->assertNotEmpty($state['diagnostics']);
        $this->assertSame($state, $state);
        foreach (array_keys($state) as $field) {
            $copy = $state;
            if ($copy[$field] !== []) {
                unset($copy[$field][array_key_first($copy[$field])]);
            } else {
                $copy[$field]['corruption'] = true;
            }
            $this->assertComparatorFails($state, $copy);
        }
        foreach (['line', 'subject', 'message', 'sources'] as $field) {
            $copy = $state;
            $copy['diagnostics'][0][$field] = $field === 'line' ? 999 : 'changed';
            $this->assertSame(count($state['diagnostics']), count($copy['diagnostics']));
            $this->assertComparatorFails($state, $copy);
        }
        $copy = $state;
        [$copy['relations'][0], $copy['relations'][1]] = [$copy['relations'][1], $copy['relations'][0]];
        $this->assertComparatorFails($state, $copy);
        $copy = $state;
        $copy['relations'][0]['metadata']['same-count-corruption'] = true;
        $this->assertComparatorFails($state, $copy);
        $query = (new GraphQuery($base->index()))->query('Known::exists', 'impact');
        $copy = $query;
        $copy['records'] = [];
        $this->assertNotEmpty($query['records']);
        $this->assertComparatorFails($query, $copy);
    }

    private function assertComparatorFails(array $oracle, array $candidate): void
    {
        try {
            Assert::assertSame($oracle, $candidate);
        } catch (ExpectationFailedException $difference) {
            $this->assertStringContainsString('identical', $difference->getMessage());

            return;
        }
        $this->fail('Full comparator missed corruption.');
    }
}
