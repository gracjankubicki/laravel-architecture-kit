<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\Saloon;

use GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\RuleCheckTestCase;

final class ConnectorCheckTest extends RuleCheckTestCase
{
    public function test_it_reports_connector_hygiene_findings(): void
    {
        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Integrations\Acme;

use Saloon\Http\Connector;

class AcmeConnector extends Connector
{
    public function resolveBaseUrl(): string
    {
        env('ACME_URL');

        return 'https://api.example.test';
    }
}
PHP;

        $findings = $this->saloonFindings('app/Http/Integrations/Acme/AcmeConnector.php', $contents);

        $this->assertHasFinding($findings, 'saloon', 'warn', 1, 'Saloon Connectors should be final classes');
        $this->assertHasFinding($findings, 'saloon', 'warn', 1, 'Saloon Connectors should use AlwaysThrowOnErrors');
        $this->assertHasFinding($findings, 'saloon', 'warn', 1, 'Saloon Connectors should use HasRateLimits');
        $this->assertHasFinding($findings, 'saloon', 'warn', 1, 'Saloon Connectors should define retry/backoff defaults');
        $this->assertHasFinding($findings, 'saloon', 'warn', $this->lineOf($contents, 'https://api'), 'Connector base URLs should come from config("services.*")');
        $this->assertHasFinding($findings, 'saloon', 'error', $this->lineOf($contents, 'env('), 'Do not call env() inside integrations');
    }

    public function test_it_allows_connector_with_required_hygiene(): void
    {
        $findings = $this->saloonFindings('app/Http/Integrations/Acme/AcmeConnector.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Integrations\Acme;

use Saloon\Http\Connector;
use Saloon\RateLimitPlugin\Traits\HasRateLimits;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;

final class AcmeConnector extends Connector
{
    use AlwaysThrowOnErrors;
    use HasRateLimits;

    protected int $tries = 3;

    public function resolveBaseUrl(): string
    {
        return config('services.acme.url');
    }
}
PHP);

        $this->assertDoesNotHaveFinding($findings, 'saloon', 'Saloon Connectors should');
        $this->assertDoesNotHaveFinding($findings, 'saloon', 'Do not call env() inside integrations');
    }
}
