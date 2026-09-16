<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

class MakeCommandTest extends TestCase
{
    /** @var array<int, Architecture> */
    private array $enabled;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enabled = [
            Architecture::ThinControllers,
            Architecture::FormRequests,
            Architecture::Actions,
            Architecture::Services,
            Architecture::QueryObjects,
            Architecture::DataObjects,
            Architecture::ValueObjects,
            Architecture::ApiResources,
            Architecture::LaravelBestPractices,
        ];

        (new ArchitectureConfig($this->tempPath.'/config/architectures.php'))->write($this->enabled);
    }

    public function test_the_agent_mode_returns_the_plan_without_writing_anything(): void
    {
        $exitCode = Artisan::call('architecture-kit:make', [
            'architecture' => 'actions',
            'name' => 'SendInvoice',
            '--agent' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['written']);
        $this->assertSame('App\\Actions\\SendInvoice', $payload['class']);
        $this->assertSame('app/Actions/SendInvoice.php', $payload['files'][0]['path']);
        $this->assertStringContainsString('public function handle()', $payload['files'][0]['contents']);

        $this->assertFileDoesNotExist($this->tempPath.'/app/Actions/SendInvoice.php');
    }

    public function test_the_interactive_mode_creates_the_file(): void
    {
        $exitCode = Artisan::call('architecture-kit:make', [
            'architecture' => 'actions',
            'name' => 'SendInvoice',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($this->tempPath.'/app/Actions/SendInvoice.php');
        $this->assertStringContainsString('namespace App\\Actions;', (new Filesystem)->get($this->tempPath.'/app/Actions/SendInvoice.php'));
    }

    public function test_it_refuses_to_overwrite_an_existing_file(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app/Actions');
        $files->put($this->tempPath.'/app/Actions/SendInvoice.php', "<?php\n// written by the developer\n");

        $exitCode = Artisan::call('architecture-kit:make', [
            'architecture' => 'actions',
            'name' => 'SendInvoice',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('written by the developer', $files->get($this->tempPath.'/app/Actions/SendInvoice.php'));
    }

    public function test_it_applies_the_naming_convention_of_the_architecture(): void
    {
        Artisan::call('architecture-kit:make', ['architecture' => 'services', 'name' => 'Billing', '--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame('app/Services/BillingService.php', $payload['files'][0]['path']);
    }

    public function test_it_does_not_duplicate_a_suffix_the_caller_already_provided(): void
    {
        Artisan::call('architecture-kit:make', ['architecture' => 'services', 'name' => 'BillingService', '--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame('app/Services/BillingService.php', $payload['files'][0]['path']);
    }

    public function test_it_supports_a_nested_namespace(): void
    {
        Artisan::call('architecture-kit:make', ['architecture' => 'actions', 'name' => 'Billing/SendInvoice', '--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame('app/Actions/Billing/SendInvoice.php', $payload['files'][0]['path']);
        $this->assertSame('App\\Actions\\Billing\\SendInvoice', $payload['class']);
    }

    public function test_it_refuses_an_architecture_the_project_did_not_enable(): void
    {
        $exitCode = Artisan::call('architecture-kit:make', [
            'architecture' => 'saloon',
            'name' => 'Billing',
            '--agent' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('E_SCAFFOLD_ARCHITECTURE_NOT_ENABLED', $payload['m']);
    }

    public function test_it_reports_an_architecture_without_a_single_target_folder(): void
    {
        (new ArchitectureConfig($this->tempPath.'/config/architectures.php'))
            ->write([...$this->enabled, Architecture::ModernPhp85]);

        $exitCode = Artisan::call('architecture-kit:make', [
            'architecture' => 'modern-php-85',
            'name' => 'Whatever',
            '--agent' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('E_SCAFFOLD_UNSUPPORTED_ARCHITECTURE', $payload['m']);
    }

    public function test_it_refuses_a_name_that_would_write_outside_the_architecture_folder(): void
    {
        $exitCode = Artisan::call('architecture-kit:make', [
            'architecture' => 'actions',
            'name' => '../../../../tmp/Evil',
            '--agent' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('E_SCAFFOLD_INVALID_NAME', $payload['m']);
        $this->assertFileDoesNotExist($this->tempPath.'/tmp/Evil.php');
    }

    public function test_it_refuses_a_name_whose_suffix_would_fail_the_audit(): void
    {
        $exitCode = Artisan::call('architecture-kit:make', [
            'architecture' => 'actions',
            'name' => 'CreateInvoiceData',
            '--agent' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame('E_SCAFFOLD_FORBIDDEN_NAME', $payload['m']);
    }

    public function test_every_generated_element_passes_the_project_audit(): void
    {
        $elements = [
            'actions' => 'SendInvoice',
            'services' => 'Billing',
            'query-objects' => 'OverdueInvoices',
            'data-objects' => 'Invoice',
            'value-objects' => 'Money',
            'api-resources' => 'Invoice',
            'form-requests' => 'StoreInvoice',
            'thin-controllers' => 'Invoice',
        ];

        foreach ($elements as $architecture => $name) {
            $this->assertSame(
                0,
                Artisan::call('architecture-kit:make', ['architecture' => $architecture, 'name' => $name]),
                "Scaffolding [{$architecture}] failed.",
            );
        }

        // Scaffolding creates classes, but does not register endpoints.
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run($this->enabled, changedOnly: false, routes: new RouteMap);

        $this->assertSame([], array_map(
            fn ($finding): string => "{$finding->rule} {$finding->path}:{$finding->line} {$finding->message}",
            $result->findings,
        ));
    }

    public function test_it_outputs_agent_schema(): void
    {
        Artisan::call('architecture-kit:make', ['--schema' => true]);
        $schema = json_decode(trim(Artisan::output()), true);

        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        $this->assertArrayHasKey('oneOf', $schema);
    }
}
