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
}
