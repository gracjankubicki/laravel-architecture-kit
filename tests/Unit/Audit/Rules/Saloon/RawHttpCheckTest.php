<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\Saloon;

use GracjanKubicki\ArchitectureKit\Tests\Unit\Audit\Rules\RuleCheckTestCase;

final class RawHttpCheckTest extends RuleCheckTestCase
{
    public function test_it_reports_raw_outbound_http_outside_integrations(): void
    {
        $contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Actions;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Http as ClientHttp;

final class SyncDocument
{
    public function handle(): void
    {
        ClientHttp::get('https://example.test');
        \Illuminate\Support\Facades\Http::post('https://example.test');
        new Client();
        curl_init('https://example.test');
        file_get_contents('https://example.test');
    }
}
PHP;

        $findings = $this->saloonFindings('app/Actions/SyncDocument.php', $contents);

        $this->assertHasFinding($findings, 'raw-http', 'error', $this->lineOf($contents, 'ClientHttp::get'), 'Raw Laravel Http:: calls are forbidden');
        $this->assertHasFinding($findings, 'raw-http', 'error', $this->lineOf($contents, 'Facades\Http::post'), 'Raw Laravel Http:: calls are forbidden');
        $this->assertHasFinding($findings, 'raw-http', 'error', $this->lineOf($contents, 'new Client'), 'Direct Guzzle clients are forbidden');
        $this->assertHasFinding($findings, 'raw-http', 'error', $this->lineOf($contents, 'curl_init'), 'curl_* calls are forbidden');
        $this->assertHasFinding($findings, 'raw-http', 'error', $this->lineOf($contents, 'file_get_contents'), 'Outbound file_get_contents(http...) is forbidden');
    }

    public function test_it_ignores_raw_http_inside_integration_paths(): void
    {
        $findings = $this->saloonFindings('app/Http/Integrations/Acme/Support/Probe.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Integrations\Acme\Support;

final class Probe
{
    public function ping(): void
    {
        Http::get('https://example.test');
    }
}
PHP);

        $this->assertDoesNotHaveFinding($findings, 'raw-http', 'Raw Laravel Http:: calls are forbidden');
    }
}
