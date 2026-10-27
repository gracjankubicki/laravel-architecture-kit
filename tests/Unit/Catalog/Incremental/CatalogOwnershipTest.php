<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog\Incremental;

use GracjanKubicki\ArchitectureKit\Catalog\CatalogDiagnostic;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogElement;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogRelation;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogContribution;
use GracjanKubicki\ArchitectureKit\Catalog\Incremental\CatalogUpdater;

final class CatalogOwnershipTest extends IncrementalFixture
{
    public function test_shared_logical_record_and_duplicate_diagnostic_owners_are_not_deduplicated(): void
    {
        // Supplementary typed-input contract fixture: Basic PHP declarations normally have path-local IDs.
        // Shared logical IDs are legal CatalogFacts input; this is not a substitute for the source-extraction tests.
        $id = CatalogElement::resourceIdentity('function', 'logical-shared');
        $source = function (string $path, int $line) use ($id): CatalogContribution {
            $file = $this->collect([$path => '<?php function local_owner() {}'])[0]->facts;
            $owner = $file->elements[0]->id;
            $logical = new CatalogElement($id, 'logical-shared', 'function', $line, $line, 0);
            $relation = new CatalogRelation($owner, $id, 'contains', $line, $line);
            $diagnostic = new CatalogDiagnostic('shared_fixture', 'Repeated local diagnostic.', $line, $id);
            $facts = new CatalogFacts($path, [...$file->elements, $logical], [...$file->relations, $relation, $relation], [$diagnostic, $diagnostic]);

            return new CatalogContribution($facts, hash('sha256', $path.':'.$line));
        };
        $a = $source('app/A.php', 1);
        $b = $source('app/B.php', 2);
        $updater = new CatalogUpdater;
        $base = $updater->prepare([$a, $b], $this->identity(), 'session');
        $this->assertSame(['app/A.php', 'app/B.php'], array_column($base->exportState()['elements'][$id]['sources'], 'path'));
        $this->assertCount(4, $base->exportState()['diagnostics']);
        $generation = $updater->patch($base, [], ['app/A.php'], $this->identity(), 'session', '1', '1')->target;
        $this->assertSame((new CatalogIndex([$b->facts]))->exportState(), $generation->exportState());
        $this->assertSame(['app/B.php'], array_column($generation->exportState()['elements'][$id]['sources'], 'path'));
        $this->assertCount(2, $generation->exportState()['diagnostics']);
        $b = $source('app/B.php', 7);
        $generation = $updater->patch($generation, [$b], [], $this->identity(), 'session', '2', '2')->target;
        $this->assertSame((new CatalogIndex([$b->facts]))->exportState(), $generation->exportState());
        $this->assertSame([7, 7], array_column($generation->exportState()['diagnostics'], 'line'));
        $generation = $updater->patch($generation, [], ['app/B.php'], $this->identity(), 'session', '3', '3')->target;
        $this->assertSame((new CatalogIndex([]))->exportState(), $generation->exportState());
        $this->assertSame([], $generation->index()->names);
    }
}
