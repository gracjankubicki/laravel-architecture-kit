<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\ArchitectureContext;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

final class ArchitectureContextCommandTest extends TestCase
{
    public function test_cli_resolves_fqcn_and_path_with_deterministic_bounded_agent_output(): void
    {
        $this->writeFixture();

        $exit = Artisan::call('architecture-kit:context', [
            'subject' => 'App\Actions\FetchDocument',
            '--agent' => true,
            '--limit' => 1,
        ]);
        $fqcn = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('architecture-context', $fqcn['cmd']);
        $this->assertSame('App\Actions\FetchDocument', $fqcn['subject']['name']);
        $this->assertSame('application', $fqcn['subject']['role']);
        $this->assertCount(1, $fqcn['dependencies']);
        $this->assertSame('App\Documents\Ports\DocumentGateway', $fqcn['dependencies'][0]['symbol']);
        $this->assertCount(1, $fqcn['dependents']);
        $this->assertSame('App\Http\Controllers\DocumentController', $fqcn['dependents'][0]['symbol']);
        $this->assertTrue($fqcn['trunc']);
        $this->assertContains('app/Actions/FetchDocument.php', $fqcn['inspect']);

        $exit = Artisan::call('architecture-kit:context', [
            'subject' => 'app/Actions/FetchDocument.php',
            '--agent' => true,
        ]);
        $path = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame($fqcn['subject'], $path['subject']);
        $this->assertSame($fqcn['dependencies'], $path['dependencies']);
    }

    public function test_cli_fails_closed_for_missing_and_ambiguous_subjects(): void
    {
        $this->writeConfig([Architecture::Actions]);
        $this->writeFile('app/Actions/Multiple.php', <<<'PHP'
<?php

namespace App\Actions;

final class FirstAction
{
}

final class SecondAction
{
}
PHP);

        $exit = Artisan::call('architecture-kit:context', [
            'subject' => 'app/Actions/Multiple.php',
            '--agent' => true,
        ]);
        $ambiguous = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('E_CONTEXT_SUBJECT_AMBIGUOUS', $ambiguous['m']);

        $exit = Artisan::call('architecture-kit:context', [
            'subject' => 'App\Actions\Missing',
            '--agent' => true,
        ]);
        $missing = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('E_CONTEXT_SUBJECT_NOT_FOUND', $missing['m']);
    }

    public function test_cli_schema_and_mcp_use_the_same_context_contract(): void
    {
        $this->writeFixture();

        $exit = Artisan::call('architecture-kit:context', ['--schema' => true]);
        $schema = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('Architecture Kit architecture context agent output', $schema['title']);
        $this->assertSame('architecture-context', $schema['oneOf'][0]['properties']['cmd']['const']);

        Artisan::call('architecture-kit:context', [
            'subject' => 'App\Actions\FetchDocument',
            '--agent' => true,
        ]);
        $cli = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        ArchitectureKitServer::tool(ArchitectureContext::class, [
            'subject' => 'App\Actions\FetchDocument',
        ])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('cmd', $cli['cmd'])
                ->where('subject', $cli['subject'])
                ->where('dependencies', $cli['dependencies'])
                ->where('dependents', $cli['dependents'])
                ->where('violations', $cli['violations'])
                ->where('inspect', $cli['inspect'])
                ->where('next', $cli['next'])
                ->etc()
            );
    }

    private function writeFixture(): void
    {
        $this->writeConfig([Architecture::Actions, Architecture::PortsAndAdapters]);
        $this->writeFile('app/Documents/Ports/DocumentGateway.php', <<<'PHP'
<?php

namespace App\Documents\Ports;

interface DocumentGateway
{
    public function fetch(): string;
}
PHP);
        $this->writeFile('app/Actions/FetchDocument.php', <<<'PHP'
<?php

namespace App\Actions;

use App\Documents\Ports\DocumentGateway;

final class FetchDocument
{
    public function __construct(private DocumentGateway $gateway)
    {
    }

    public function handle(): string
    {
        return $this->gateway->fetch();
    }
}
PHP);
        $this->writeFile('app/Http/Controllers/DocumentController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Actions\FetchDocument;

final class DocumentController
{
    public function __construct(private FetchDocument $action)
    {
    }
}
PHP);
        $this->writeFile('app/Http/Controllers/OtherController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Actions\FetchDocument;

final class OtherController
{
    public function __construct(private FetchDocument $action)
    {
    }
}
PHP);
    }

    /** @param array<int, Architecture|string> $enabled */
    private function writeConfig(array $enabled): void
    {
        (new ArchitectureConfig($this->tempPath.'/config/architectures.php'))->write($enabled);
    }

    private function writeFile(string $path, string $contents): void
    {
        $files = new Filesystem;
        $absolute = $this->tempPath.'/'.$path;
        $files->ensureDirectoryExists(dirname($absolute));
        $files->put($absolute, $contents);
    }
}
