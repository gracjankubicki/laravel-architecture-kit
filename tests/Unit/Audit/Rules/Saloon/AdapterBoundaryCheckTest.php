<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\Saloon;

use GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\RuleCheckTestCase;

final class AdapterBoundaryCheckTest extends RuleCheckTestCase
{
    public function test_it_reports_integration_usage_from_forbidden_adapter_paths(): void
    {
        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Integrations\Acme\AcmeConnector;

final class AcmeController
{
    public function __invoke(): void
    {
        $connector = new AcmeConnector();
        $connector->send(new AcmeRequest());
    }
}
PHP;

        $findings = $this->saloonFindings('app/Http/Controllers/AcmeController.php', $contents);

        $this->assertHasFinding($findings, 'saloon', 'error', $this->lineOf($contents, 'use App\\Http\\Integrations'), 'must not import integration classes');
        $this->assertHasFinding($findings, 'saloon', 'error', $this->lineOf($contents, 'new AcmeConnector'), 'must not instantiate Saloon Connectors');
        $this->assertHasFinding($findings, 'saloon', 'error', $this->lineOf($contents, '->send'), 'must not send Saloon requests');
    }

    public function test_it_allows_integration_usage_from_actions(): void
    {
        $findings = $this->saloonFindings('app/Actions/SyncAcme.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions;

use App\Http\Integrations\Acme\AcmeConnector;

final class SyncAcme
{
    public function handle(): void
    {
        $connector = new AcmeConnector();
        $connector->send(new AcmeRequest());
    }
}
PHP);

        $this->assertDoesNotHaveFinding($findings, 'saloon', 'must not import integration classes');
    }
}
