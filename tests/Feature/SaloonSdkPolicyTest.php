<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\FindingCodeRegistry;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;

final class SaloonSdkPolicyTest extends TestCase
{
    public function test_sdk_adapter_may_map_an_integration_dto_internally(): void
    {
        $this->writeFile('app/Advertising/Adapters/GoogleAdsCampaignReader.php', <<<'PHP'
<?php

namespace App\Advertising\Adapters;

use App\Http\Integrations\Reporting\Dto\CampaignData;
use Google\Ads\GoogleAds\Lib\V25\GoogleAdsClient;

final readonly class GoogleAdsCampaignReader
{
    public function __construct(private GoogleAdsClient $client)
    {
    }

    private function map(object $campaign): CampaignData
    {
        return new CampaignData((string) $campaign->id);
    }
}
PHP);

        $this->assertSame([], $this->findingCodes([Architecture::Saloon]));
    }

    #[DataProvider('httpAdapterProvider')]
    public function test_http_adapters_cannot_depend_on_a_concrete_adapter_that_implements_a_port(
        string $path,
        string $namespace,
        string $class,
    ): void {
        $this->writeCampaignPortAndAdapter();
        $this->writeFile($path, <<<PHP
<?php

namespace {$namespace};

use App\Advertising\Adapters\GoogleAdsCampaignReader;

final class {$class}
{
    public function __construct(private GoogleAdsCampaignReader \$reader)
    {
    }
}
PHP);

        if ($class === 'CampaignResource') {
            $this->writeFile($path, <<<PHP
<?php

namespace {$namespace};

use App\Advertising\Adapters\GoogleAdsCampaignReader;
use Illuminate\Http\Resources\Json\JsonResource;

final class {$class} extends JsonResource
{
    public function __construct(private GoogleAdsCampaignReader \$reader)
    {
    }
}
PHP);
        }

        $this->assertSame(['E_PORT_BYPASS'], $this->findingCodes([Architecture::PortsAndAdapters]));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function httpAdapterProvider(): iterable
    {
        yield 'controller' => ['app/Http/Controllers/CampaignController.php', 'App\Http\Controllers', 'CampaignController'];
        yield 'form request' => ['app/Http/Requests/CampaignRequest.php', 'App\Http\Requests', 'CampaignRequest'];
        yield 'API resource' => ['app/Http/Resources/CampaignResource.php', 'App\Http\Resources', 'CampaignResource'];
    }

    public function test_port_contract_rejects_integration_dtos_in_aliased_nullable_and_union_types(): void
    {
        $this->writeFile('app/Advertising/Ports/CampaignReader.php', <<<'PHP'
<?php

namespace App\Advertising\Ports;

use App\Http\Integrations\Reporting\Dto\CampaignData as ReportingCampaign;

/** Keeps campaign workflows independent from external provider APIs. */
interface CampaignReader
{
    public function find(?ReportingCampaign $campaign): string;

    public function store(\App\Http\Integrations\Reporting\Dto\CampaignData|false $campaign): string;

    public function merge(ReportingCampaign&\Stringable $campaign): string;
}
PHP);

        $this->assertSame([
            'E_PORTS_AND_ADAPTERS',
            'E_PORTS_AND_ADAPTERS',
            'E_PORTS_AND_ADAPTERS',
        ], $this->findingCodes([
            Architecture::PortsAndAdapters,
        ]));
    }

    public function test_action_uses_the_port_while_both_sdk_and_saloon_adapters_stay_behind_it(): void
    {
        $this->writeCampaignPortAndAdapter();
        $this->writeFile('app/Advertising/Adapters/ReportingCampaignReader.php', <<<'PHP'
<?php

namespace App\Advertising\Adapters;

use App\Advertising\Ports\CampaignReader;

final class ReportingCampaignReader implements CampaignReader
{
    public function find(): string
    {
        return 'saloon';
    }
}
PHP);
        $this->writeFile('app/Actions/ReadCampaign.php', <<<'PHP'
<?php

namespace App\Actions;

use App\Advertising\Ports\CampaignReader;

final class ReadCampaign
{
    public function __construct(private CampaignReader $reader)
    {
    }

    public function handle(): string
    {
        return $this->reader->find();
    }
}
PHP);

        $this->assertSame([], $this->findingCodes([
            Architecture::PortsAndAdapters,
            Architecture::Saloon,
        ]));
    }

    public function test_port_bypass_is_inactive_without_ports_and_adapters(): void
    {
        $this->writeCampaignPortAndAdapter();
        $this->writeFile('app/Http/Controllers/CampaignController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Advertising\Adapters\GoogleAdsCampaignReader;

final class CampaignController
{
    public function __construct(private GoogleAdsCampaignReader $reader)
    {
    }
}
PHP);

        $this->assertSame([], $this->findingCodes([]));
    }

    private function writeCampaignPortAndAdapter(): void
    {
        $this->writeFile('app/Advertising/Ports/CampaignReader.php', <<<'PHP'
<?php

namespace App\Advertising\Ports;

/** Keeps campaign workflows independent from external provider APIs. */
interface CampaignReader
{
    public function find(): string;
}
PHP);
        $this->writeFile('app/Advertising/Adapters/GoogleAdsCampaignReader.php', <<<'PHP'
<?php

namespace App\Advertising\Adapters;

use App\Advertising\Ports\CampaignReader;

final class GoogleAdsCampaignReader implements CampaignReader
{
    public function find(): string
    {
        return 'sdk';
    }
}
PHP);
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     * @return array<int, string>
     */
    private function findingCodes(array $enabled): array
    {
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run($enabled, changedOnly: false);
        $registry = new FindingCodeRegistry;
        $codes = array_map(fn ($finding): string => $registry->codeFor($finding), $result->findings);
        sort($codes);

        return $codes;
    }

    private function writeFile(string $path, string $contents): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $contents);
    }
}
