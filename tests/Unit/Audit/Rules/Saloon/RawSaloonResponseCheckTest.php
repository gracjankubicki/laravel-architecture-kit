<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\Saloon;

use GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\RuleCheckTestCase;

final class RawSaloonResponseCheckTest extends RuleCheckTestCase
{
    public function test_it_warns_when_raw_saloon_response_is_consumed_outside_integrations(): void
    {
        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions;

final class SyncAcme
{
    public function handle($connector): void
    {
        $response = $connector->send(new AcmeRequest());
        $response->json();
    }
}
PHP;

        $findings = $this->saloonFindings('app/Actions/SyncAcme.php', $contents);

        $this->assertHasFinding($findings, 'saloon', 'warn', $this->lineOf($contents, '->json'), 'must not consume raw Saloon responses');
    }

    public function test_it_allows_raw_response_consumption_inside_integrations(): void
    {
        $findings = $this->saloonFindings('app/Http/Integrations/Acme/AcmeConnector.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Integrations\Acme;

final class AcmeConnector
{
    public function handle($connector): void
    {
        $response = $connector->send(new AcmeRequest());
        $response->json();
    }
}
PHP);

        $this->assertDoesNotHaveFinding($findings, 'saloon', 'must not consume raw Saloon responses');
    }
}
