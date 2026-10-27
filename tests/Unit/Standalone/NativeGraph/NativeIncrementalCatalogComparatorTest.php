<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone\NativeGraph;

use GracjanKubicki\ArchitectureKit\Standalone\Capture\TypedValue;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

/** Negative controls use exactly the strict typed comparison of differential tests. */
final class NativeIncrementalCatalogComparatorTest extends TestCase
{
    public function test_incremental_catalog_comparator_preserves_types_order_multiplicity_and_all_answers(): void
    {
        $oracle = ['state' => [
            'elements' => ['0' => ['id' => '0', 'metadata' => ['x' => 1.0], 'line' => 1], '00' => ['id' => '00']],
            'relations' => [['from' => '0', 'to' => '00', 'metadata' => ['execution_proven' => false]], ['from' => '00', 'to' => '0', 'metadata' => []]],
            'diagnostics' => [['code' => 'unresolved_dispatch', 'line' => 1], ['code' => 'other', 'line' => 2]],
            'descriptorReceivers' => ['a' => '@types:["A"]', 'b' => '@parent:B'],
            'names' => ['zero' => ['0', '00']], 'out' => [0 => [0, 1]], 'in' => ['00' => [0, 1]], 'hierarchyLimits' => ['0' => true],
        ], 'answers' => array_fill_keys(['search', 'context', 'path', 'impact', 'test_candidates'], ['status' => 'found', 'records' => [['id' => '0'], ['id' => '00']]])];
        Assert::assertSame(TypedValue::encode($oracle), TypedValue::encode($oracle));
        foreach (array_keys($oracle['state']) as $field) {
            $copy = $oracle;
            $key = array_key_first($copy['state'][$field]);
            $copy['state'][$field][$key] = ['corruption' => true];
            $this->assertSame(count($oracle['state'][$field]), count($copy['state'][$field]));
            $this->reject($oracle, $copy);
        }
        foreach (['search', 'context', 'path', 'impact', 'test_candidates'] as $query) {
            $copy = $oracle;
            $copy['answers'][$query]['records'] = array_reverse($copy['answers'][$query]['records']);
            $this->reject($oracle, $copy);
        }
        $copy = $oracle;
        $copy['state']['elements'][0]['id'] = 0;
        $this->reject($oracle, $copy);
        $copy = $oracle;
        $copy['state']['elements'][0]['metadata']['x'] = 1;
        $this->reject($oracle, $copy);
        $copy = $oracle;
        $copy['state']['elements'][0]['line']++;
        $this->reject($oracle, $copy);
        foreach (['relations', 'diagnostics'] as $field) {
            $copy = $oracle;
            $copy['state'][$field] = array_reverse($copy['state'][$field]);
            $this->reject($oracle, $copy);
            $copy['state'][$field][1] = $copy['state'][$field][0];
            $this->assertSame(count($oracle['state'][$field]), count($copy['state'][$field]));
            $this->reject($oracle, $copy);
        }
    }

    private function reject(array $expected, array $actual): void
    {
        try {
            Assert::assertSame(TypedValue::encode($expected), TypedValue::encode($actual));
        } catch (ExpectationFailedException $failure) {
            $this->assertStringContainsString('identical', $failure->getMessage());

            return;
        }
        $this->fail('Strict comparator missed a same-count mutation.');
    }
}
