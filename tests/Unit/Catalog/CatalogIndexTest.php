<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\PhpCatalogExtractor;
use PHPUnit\Framework\TestCase;

final class CatalogIndexTest extends TestCase
{
    private function facts(string $path, string $source): CatalogFacts
    {
        return (new PhpCatalogExtractor)->extract(new FileContext($path, '<?php '.$source));
    }

    public function test_every_core_contract_has_resolved_type_evidence_and_no_lookalike_match(): void
    {
        foreach (CatalogIndex::CONTRACT_ROLES as $contract => $role) {
            $index = new CatalogIndex([$this->facts('app/Example.php', 'namespace App; use \\'.$contract.' as ParentContract; class Example extends ParentContract {} class Decoy {}')]);
            $example = $index->elements[$index->namedTypes('App\\Example')[0]];
            $decoy = $index->elements[$index->namedTypes('App\\Decoy')[0]];
            $this->assertContains($role, $example['roles'], $contract);
            $this->assertSame('php-contract', $example['role_evidence'][0]['basis']);
            $this->assertSame($contract, $example['role_evidence'][0]['contract']);
            $this->assertSame([], $decoy['roles']);
        }
    }

    public function test_cross_file_inheritance_is_recomposed_and_does_not_need_caller_reparse(): void
    {
        $child = $this->facts('app/Invoice.php', 'namespace App; class Invoice extends BaseInvoice {}');
        $base = $this->facts('app/BaseInvoice.php', 'namespace App; class BaseInvoice extends \\Illuminate\\Database\\Eloquent\\Model {}');
        $index = new CatalogIndex([$child, $base]);
        $invoice = $index->elements[$index->namedTypes('App\\Invoice')[0]];
        $this->assertContains('model', $invoice['roles']);
        $this->assertSame('app/BaseInvoice.php', $invoice['role_evidence'][0]['path']);
        $changed = $this->facts('app/BaseInvoice.php', 'namespace App; class BaseInvoice {}');
        $new = new CatalogIndex([$child, $changed]);
        $this->assertSame([], $new->elements[$new->namedTypes('App\\Invoice')[0]]['roles']);
    }

    public function test_placement_is_a_hint_and_does_not_manufacture_execution(): void
    {
        $index = new CatalogIndex([$this->facts('app/Actions/Run.php', 'namespace App; class Run { public function handle() {} }')]);
        $run = $index->elements[$index->namedTypes('App\\Run')[0]];
        $this->assertSame(['action'], $run['roles']);
        $this->assertSame('placement-convention', $run['role_evidence'][0]['basis']);
        $this->assertSame(['contains', 'contains'], array_column($index->relations, 'kind'));
    }

    public function test_application_role_conventions_preserve_type_links_without_inventing_execution(): void
    {
        foreach (['Services' => 'service', 'Actions' => 'action', 'Queries' => 'query', 'DTOs' => 'dto', 'Data' => 'dto',
            'ValueObjects' => 'value-object', 'Ports' => 'port', 'Contracts' => 'port', 'Adapters' => 'adapter', 'Gateways' => 'gateway'] as $folder => $role) {
            $fact = $this->facts('app/'.$folder.'/Example.php', 'namespace App; interface Dependency {} class Example { public function accept(Dependency $value): Dependency { return $value; } }');
            $index = new CatalogIndex([CatalogFacts::fromArray($fact->path, $fact->toArray())]);
            $example = $index->elements[$index->namedTypes('App\\Example')[0]];
            $this->assertContains($role, $example['roles'], $folder);
            $this->assertSame('placement-convention', $example['role_evidence'][0]['basis']);
            $method = $index->names[strtolower('App\\Example::accept')][0];
            $links = array_values(array_filter($index->relations, fn ($edge) => $edge['from'] === $method && $edge['to'] === $index->namedTypes('App\\Dependency')[0]));
            $this->assertNotEmpty($links, $folder);
            $this->assertNotContains('calls', array_column($index->relations, 'kind'));
        }
        $index = new CatalogIndex([$this->facts('app/Example.php', 'namespace App; class PaymentService {} class PaymentDTO {}')]);
        foreach (['App\\PaymentService', 'App\\PaymentDTO'] as $name) {
            $this->assertSame([], $index->elements[$index->namedTypes($name)[0]]['roles']);
        }
    }

    public function test_ambiguous_types_are_not_selected_and_cycles_terminate(): void
    {
        $index = new CatalogIndex([
            $this->facts('app/A.php', 'namespace App; class A extends B {}'),
            $this->facts('app/B.php', 'namespace App; class B extends A {}'),
            $this->facts('app/Bcopy.php', 'namespace App; class B {}'),
        ]);
        $this->assertCount(2, $index->namedTypes('App\\B'));
        $this->assertContains('ambiguous_type', array_column($index->diagnostics, 'code'));
        $edge = array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'extends' && $row['path'] === 'app/A.php'))[0];
        $this->assertSame('php:App\\B', $edge['to']);
        $this->assertSame('conditional', $edge['resolution']);
        $this->assertArrayNotHasKey($edge['to'], $index->elements);
    }
}
