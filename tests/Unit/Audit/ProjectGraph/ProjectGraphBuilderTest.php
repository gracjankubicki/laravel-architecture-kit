<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\ProjectGraph;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use PHPUnit\Framework\TestCase;

final class ProjectGraphBuilderTest extends TestCase
{
    public function test_it_builds_resolved_symbols_and_evidenced_edges_for_multiple_declarations(): void
    {
        $graph = (new ProjectGraphBuilder)->build([
            new FileContext('app/Actions/RunReport.php', <<<'PHP'
<?php

namespace App\Actions;

use App\Contracts\ReportGateway as Gateway;
use App\Data\ReportData;

final class RunReport
{
    public function __construct(private Gateway $gateway)
    {
    }

    public function handle(): ReportData
    {
        return new ReportData();
    }
}

final class RunReportFallback
{
    public function __construct(private Gateway $gateway)
    {
    }
}
PHP),
            new FileContext('app/Contracts/ReportGateway.php', <<<'PHP'
<?php

namespace App\Contracts;

interface ReportGateway
{
    public function fetch(): array;
}
PHP),
            new FileContext('app/Data/ReportData.php', <<<'PHP'
<?php

namespace App\Data;

final readonly class ReportData
{
}
PHP),
        ]);

        $this->assertNotNull($graph->symbol('App\Actions\RunReport'));
        $this->assertCount(2, $graph->symbolsAt('app/Actions/RunReport.php'));
        $this->assertTrue(collect($graph->dependenciesOf('App\Actions\RunReport'))->contains(
            fn ($edge): bool => $edge->to === 'App\Contracts\ReportGateway'
                && $edge->kind === 'parameter'
                && $edge->strong
                && $edge->line > 1,
        ));
        $this->assertTrue(collect($graph->dependenciesOf('App\Actions\RunReport'))->contains(
            fn ($edge): bool => $edge->to === 'App\Data\ReportData'
                && $edge->kind === 'new'
                && $edge->strong,
        ));
    }

    public function test_it_marks_class_references_and_eloquent_relations_as_weak_context_only_edges(): void
    {
        $graph = (new ProjectGraphBuilder)->build([
            new FileContext('app/Models/Invoice.php', <<<'PHP'
<?php

namespace App\Models;

final class Invoice
{
    public function customer(): mixed
    {
        return $this->belongsTo(Customer::class);
    }

    public function map(): array
    {
        return [Customer::class];
    }
}
PHP),
            new FileContext('app/Models/Customer.php', <<<'PHP'
<?php

namespace App\Models;

final class Customer
{
}
PHP),
        ]);

        $edges = $graph->dependenciesOf('App\Models\Invoice');

        $this->assertTrue(collect($edges)->contains(
            fn ($edge): bool => $edge->to === 'App\Models\Customer'
                && $edge->kind === 'eloquent-relation'
                && ! $edge->strong,
        ));
        $this->assertTrue(collect($edges)->contains(
            fn ($edge): bool => $edge->to === 'App\Models\Customer'
                && $edge->kind === 'class-reference'
                && ! $edge->strong,
        ));
    }

    public function test_it_records_class_constants_and_enum_cases_as_strong_dependencies(): void
    {
        $graph = (new ProjectGraphBuilder)->build([
            new FileContext('app/Actions/PayInvoice.php', <<<'PHP'
<?php

namespace App\Actions;

use App\Infrastructure\InfrastructureMode;
use App\Infrastructure\PaymentAdapter;

final class PayInvoice
{
    public function timeout(): int
    {
        return PaymentAdapter::DEFAULT_TIMEOUT;
    }

    public function mode(): InfrastructureMode
    {
        return InfrastructureMode::Live;
    }
}
PHP),
            new FileContext('app/Infrastructure/PaymentAdapter.php', <<<'PHP'
<?php

namespace App\Infrastructure;

final class PaymentAdapter
{
    public const DEFAULT_TIMEOUT = 10;
}
PHP),
            new FileContext('app/Infrastructure/InfrastructureMode.php', <<<'PHP'
<?php

namespace App\Infrastructure;

enum InfrastructureMode
{
    case Live;
}
PHP),
        ]);

        $edges = $graph->dependenciesOf('App\Actions\PayInvoice');

        $this->assertTrue(collect($edges)->contains(
            fn ($edge): bool => $edge->to === 'App\Infrastructure\PaymentAdapter'
                && $edge->kind === 'class-constant'
                && $edge->strong,
        ));
        $this->assertTrue(collect($edges)->contains(
            fn ($edge): bool => $edge->to === 'App\Infrastructure\InfrastructureMode'
                && $edge->kind === 'class-constant'
                && $edge->strong,
        ));
    }

    public function test_catalog_blade_prepares_source_before_any_raw_php_parse_and_keeps_source_positions(): void
    {
        $source = <<<'SOURCE'
{{-- <?php class ??? ?> --}}
@php
\App\Read::run();
@endphp
<?php class Actual { public function action() {} } ?>
SOURCE;
        $path = 'resources/views/mapped.blade.php';
        $this->assertNull((new FileContext($path, $source))->ast(), 'The raw Blade comment deliberately contains invalid PHP.');
        $graph = (new ProjectGraphBuilder(impact: true, catalog: true))->build([new FileContext($path, $source)]);
        $this->assertNotNull($graph->symbol('Actual'));
        $facts = $graph->catalogFacts[0];
        $this->assertNotContains('parse_error', array_column(array_map(fn ($diagnostic) => $diagnostic->toArray(), $facts->diagnostics), 'code'));
        $actual = array_values(array_filter($facts->elements, fn ($element) => $element->kind === 'class'));
        $this->assertCount(1, $actual);
        $this->assertSame('Actual', $actual[0]->name);
        $this->assertSame(5, $actual[0]->line);
        $this->assertSame(strpos($source, 'class Actual'), $actual[0]->offset);
        $this->assertNotEmpty($facts->relations);
    }

    public function test_catalog_preparation_keeps_ordinary_php_legacy_symbols_edges_and_impact_facts(): void
    {
        $source = '<?php namespace App; class Example { public function work(Service $service) { $service->run(); } } class Service { public function run() {} }';
        $ordinary = (new ProjectGraphBuilder(impact: true))->collect(new FileContext('app/Example.php', $source));
        $catalog = (new ProjectGraphBuilder(impact: true, catalog: true))->collect(new FileContext('app/Example.php', $source));
        $this->assertEquals($ordinary->symbols, $catalog->symbols);
        $this->assertEquals($ordinary->edges, $catalog->edges);
        $this->assertEquals($ordinary->testInvocations, $catalog->testInvocations);
        $this->assertEquals($ordinary->impact, $catalog->impact);
    }
}
