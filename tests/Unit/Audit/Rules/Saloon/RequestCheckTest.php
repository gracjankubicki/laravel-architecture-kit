<?php

declare(strict_types=1);

namespace Taqie\ArchitectureKit\Tests\Unit\Audit\Rules\Saloon;

use Taqie\ArchitectureKit\Tests\Unit\Audit\Rules\RuleCheckTestCase;

final class RequestCheckTest extends RuleCheckTestCase
{
    public function test_it_reports_request_hygiene_findings(): void
    {
        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Integrations\Acme\Requests;

use GuzzleHttp\Client;
use Saloon\Http\Request;

class FetchAcme extends Request
{
    public function resolveEndpoint(): string
    {
        Http::get('https://example.test');
        new Client();

        return 'https://api.example.test/users';
    }
}
PHP;

        $findings = $this->saloonFindings('app/Http/Integrations/Acme/Requests/FetchAcme.php', $contents);

        $this->assertHasFinding($findings, 'saloon', 'warn', 1, 'Saloon Request classes should be final');
        $this->assertHasFinding($findings, 'saloon', 'warn', 1, 'Saloon endpoint classes should use the Request suffix');
        $this->assertHasFinding($findings, 'saloon', 'error', $this->lineOf($contents, 'Http::get'), 'Do not call the Laravel HTTP facade inside Saloon Requests');
        $this->assertHasFinding($findings, 'saloon', 'error', $this->lineOf($contents, 'new Client'), 'Do not create direct Guzzle clients inside Saloon Requests');
        $this->assertHasFinding($findings, 'saloon', 'error', $this->lineOf($contents, 'https://example.test'), 'Saloon Request endpoints must be relative paths');
    }

    public function test_it_allows_final_request_with_relative_endpoint(): void
    {
        $findings = $this->saloonFindings('app/Http/Integrations/Acme/Requests/FetchAcmeRequest.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Integrations\Acme\Requests;

use Saloon\Http\Request;

final class FetchAcmeRequest extends Request
{
    public function resolveEndpoint(): string
    {
        return '/users';
    }
}
PHP);

        $this->assertDoesNotHaveFinding($findings, 'saloon', 'Saloon Request');
        $this->assertDoesNotHaveFinding($findings, 'saloon', 'Saloon endpoint');
    }
}
