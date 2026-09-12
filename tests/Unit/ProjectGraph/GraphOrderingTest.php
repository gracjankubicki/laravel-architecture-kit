<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\ProjectGraph;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\DependencyEdge;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntry;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphSnapshot;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectSymbol;
use PHPUnit\Framework\TestCase;

/**
 * The ordering is what `--limit` and every diffable output rely on, and it is now
 * produced by sort keys rather than by comparing arrays. These compare the result against
 * the comparison it replaced, so a key that loses a field or mis-pads a number shows up
 * here instead of quietly reshuffling somebody's output.
 */
final class GraphOrderingTest extends TestCase
{
    public function test_symbols_keep_the_order_the_array_comparison_produced(): void
    {
        // Sorted from the builder's raw contributions, not from the finished graph:
        // re-sorting an already sorted list would pass whatever the keys did.
        $expected = $this->raw()[0];
        usort($expected, static fn (ProjectSymbol $left, ProjectSymbol $right): int => [$left->name, $left->path, $left->line] <=> [$right->name, $right->path, $right->line]);

        $this->assertSame(
            array_map($this->describeSymbol(...), $expected),
            array_map($this->describeSymbol(...), $this->graph()->symbols),
        );
    }

    public function test_edges_keep_the_order_the_array_comparison_produced(): void
    {
        $unique = [];

        foreach ($this->raw()[1] as $edge) {
            if (strcasecmp($edge->from, $edge->to) === 0) {
                continue;
            }

            $unique[strtolower(implode('|', [$edge->from, $edge->to, $edge->path, (string) $edge->line, $edge->kind]))] = $edge;
        }

        $expected = array_values($unique);
        usort($expected, static fn (DependencyEdge $left, DependencyEdge $right): int => [$left->from, $left->to, $left->path, $left->line, $left->kind] <=> [$right->from, $right->to, $right->path, $right->line, $right->kind]);

        $this->assertNotSame([], $expected);
        $this->assertSame(
            array_map($this->describeEdge(...), $expected),
            array_map($this->describeEdge(...), $this->graph()->edges),
        );
    }

    public function test_a_line_number_sorts_numerically_rather_than_as_text(): void
    {
        // Zero padding is the whole reason the keys can be strings: without it line 100
        // would sort before line 9, and a relationship list would come out reordered.
        $builder = new ProjectGraphBuilder;
        $builder->addEntry(new FileGraphEntry(
            [
                new ProjectSymbol('App\\Same', 'app/Same.php', 100, 'App', 'class', 'application'),
                new ProjectSymbol('App\\Same', 'app/Same.php', 9, 'App', 'class', 'application'),
            ],
            [],
        ));

        $lines = array_map(static fn (ProjectSymbol $symbol): int => $symbol->line, $builder->finish()->symbols);

        $this->assertSame([9, 100], $lines);
    }

    public function test_a_name_that_is_a_prefix_of_another_still_sorts_first(): void
    {
        // The separator has to sort below every character a name can contain, or
        // `App\Invoice` would fall after `App\InvoiceLine`.
        $builder = new ProjectGraphBuilder;
        $builder->addEntry(new FileGraphEntry(
            [
                new ProjectSymbol('App\\InvoiceLine', 'app/InvoiceLine.php', 1, 'App', 'class', 'application'),
                new ProjectSymbol('App\\Invoice', 'app/Invoice.php', 1, 'App', 'class', 'application'),
            ],
            [],
        ));

        $names = array_map(static fn (ProjectSymbol $symbol): string => $symbol->name, $builder->finish()->symbols);

        $this->assertSame(['App\\Invoice', 'App\\InvoiceLine'], $names);
    }

    private function graph(): ProjectGraphSnapshot
    {
        $builder = new ProjectGraphBuilder;

        foreach ($this->sources() as $path => $source) {
            $builder->add(new FileContext($path, $source));
        }

        return $builder->finish();
    }

    /**
     * The contributions in the order the builder collected them, before any sorting.
     *
     * @return array{0: array<int, ProjectSymbol>, 1: array<int, DependencyEdge>}
     */
    private function raw(): array
    {
        $builder = new ProjectGraphBuilder;
        $symbols = [];
        $edges = [];

        foreach ($this->sources() as $path => $source) {
            $entry = $builder->collect(new FileContext($path, $source));
            array_push($symbols, ...$entry->symbols);
            array_push($edges, ...$entry->edges);
        }

        return [$symbols, $edges];
    }

    private function describeSymbol(ProjectSymbol $symbol): string
    {
        return $symbol->name.'|'.$symbol->path.'|'.$symbol->line;
    }

    private function describeEdge(DependencyEdge $edge): string
    {
        return $edge->from.'|'.$edge->to.'|'.$edge->path.'|'.$edge->line.'|'.$edge->kind;
    }

    /** @return array<string, string> */
    private function sources(): array
    {
        return [
            'app/Services/BillingService.php' => <<<'PHP'
<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\Money;

final class BillingService extends BaseService
{
    public function total(Invoice $invoice, InvoiceLine $line): Money
    {
        return new Money;
    }

    public function other(InvoiceLine $line, Invoice $invoice): void
    {
    }
}
PHP,
            'app/Models/Invoice.php' => "<?php\n\nnamespace App\\Models;\n\nfinal class Invoice\n{\n}\n",
            'app/Models/InvoiceLine.php' => "<?php\n\nnamespace App\\Models;\n\nfinal class InvoiceLine\n{\n}\n",
            'app/Support/Money.php' => "<?php\n\nnamespace App\\Support;\n\nfinal class Money\n{\n}\n",
            'app/Services/BaseService.php' => "<?php\n\nnamespace App\\Services;\n\nabstract class BaseService\n{\n}\n",
        ];
    }
}
