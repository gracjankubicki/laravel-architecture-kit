<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature\Standalone;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\ArchitectureCatalog;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use GracjanKubicki\ArchitectureKit\Audit\CustomRuleSet;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Composer\ProjectLayout;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Discovery\DiscoverySettings;
use GracjanKubicki\ArchitectureKit\Guidance\FileGuidance;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use GracjanKubicki\ArchitectureKit\Scaffolding\Scaffolder;
use GracjanKubicki\ArchitectureKit\Scaffolding\ScaffoldException;
use GracjanKubicki\ArchitectureKit\Standalone\Host;
use GracjanKubicki\ArchitectureKit\Standalone\Projects;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Standalone\StandaloneAudit;
use GracjanKubicki\ArchitectureKit\Standalone\StateStore;
use GracjanKubicki\ArchitectureKit\Standalone\TaskControl;
use GracjanKubicki\ArchitectureKit\Tests\Support\EngineFixture;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComposerPackageWorkflowTest extends TestCase
{
    private string $temporary;

    private string $root;

    private string $home;

    protected function setUp(): void
    {
        $this->temporary = sys_get_temp_dir().'/kit-package-'.bin2hex(random_bytes(8));
        mkdir($this->temporary, 0700);
        $this->temporary = (string) realpath($this->temporary);
        mkdir($this->root = $this->temporary.'/package');
        $this->home = $this->temporary.'/home';
        if (EngineFixture::selected() === 'mago') {
            EngineFixture::install($this->home);
        }
        file_put_contents($this->root.'/composer.json', json_encode([
            'name' => 'example/payments', 'type' => 'library',
            'require' => ['php' => '^8.3'],
            'autoload' => ['psr-4' => ['Example\\Payments\\' => 'lib/']],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        RepositoryContext::activate(null);
        (new Filesystem)->deleteDirectory($this->temporary);
    }

    private function register(array $enabled): array
    {
        $registration = (new Projects($this->home))->register($this->root, $enabled);
        (new StateStore(dirname($registration['config'])))->update('default', static fn (array $settings): array => [...$settings,
            'analysis' => ['engine' => EngineFixture::selected()]]);

        return $registration;
    }

    public function test_package_without_laravel_or_artisan_can_scaffold_audit_and_detect_new_errors(): void
    {
        $this->register(['actions', 'data-objects']);
        $context = new RepositoryContext($this->root, $this->home);
        RepositoryContext::activate($context);
        Host::create($this->home);
        $files = new Filesystem;
        $config = new ArchitectureConfig($context->configPath, $files);
        self::assertSame(['lib'], $config->auditScope()->directories);
        $scaffolder = new Scaffolder($files, $this->root, new ArchitectureCatalog($files, $this->root));
        $plan = $scaffolder->plan('actions', 'ChargePayment', [Architecture::Actions]);
        self::assertSame(['lib/Actions/ChargePayment.php'], $plan->paths());
        self::assertSame('Example\\Payments\\Actions', $plan->namespace);
        $scaffolder->write($plan);
        $initial = StandaloneAudit::run($context);
        self::assertSame(0, $initial->errors());
        $tasks = new TaskControl($context);
        self::assertSame('started', $tasks->begin('charge')['status']);
        file_put_contents($this->root.'/lib/Actions/ChargePayment.php', '<?php namespace Example\\Payments\\Actions; class ChargePayment {}');
        $audit = StandaloneAudit::run($context);
        self::assertContains('folder-purity', array_map(static fn ($finding) => $finding->rule, $audit->findings));
        self::assertNotSame('pass', $tasks->check('charge')['status']);
        self::assertFileDoesNotExist($this->root.'/artisan');
        self::assertDirectoryDoesNotExist($this->root.'/app');
        self::assertDirectoryDoesNotExist($this->root.'/.architecture-kit');
        self::assertDirectoryDoesNotExist($this->root.'/vendor');
    }

    public function test_custom_placement_controls_scaffolding_and_audit_of_the_same_directory(): void
    {
        $registration = $this->register(['actions']);
        (new StateStore(dirname($registration['config'])))->update('default', static fn (array $settings): array => [...$settings,
            'placements' => ['actions' => ['path' => 'lib/Domain/Payments', 'namespace' => 'Example\\Payments\\Domain\\Payments']]]);
        $context = new RepositoryContext($this->root, $this->home);
        RepositoryContext::activate($context);
        Host::create($this->home);
        $files = new Filesystem;
        $scaffolder = new Scaffolder($files, $this->root, new ArchitectureCatalog($files, $this->root));
        $plan = $scaffolder->plan('actions', 'ChargePayment', [Architecture::Actions]);
        self::assertSame(['lib/Domain/Payments/ChargePayment.php'], $plan->paths());
        $scaffolder->write($plan);
        self::assertSame(0, StandaloneAudit::run($context)->errors());
        file_put_contents($this->root.'/lib/Domain/Payments/ChargePayment.php', '<?php namespace Example\\Payments\\Domain\\Payments; class ChargePayment {}');
        self::assertGreaterThan(0, StandaloneAudit::run($context)->errors());
    }

    public function test_multiple_source_roots_are_audited_but_ambiguous_scaffolding_requires_selection(): void
    {
        file_put_contents($this->root.'/composer.json', '{"type":"library","autoload":{"psr-4":{"One\\\\":"lib/","Two\\\\":"other/"}}}');
        $layout = ProjectLayout::read($this->root);
        self::assertSame(['lib', 'other'], $layout->sources());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('placement is ambiguous');
        $layout->placement('actions', 'app/Actions', 'App\\Actions');
    }

    public function test_package_defaults_do_not_enable_laravel_specific_architectures(): void
    {
        $settings = $this->register([])['settings'];
        self::assertContains('actions', $settings['enabled']);
        self::assertNotContains('thin-controllers', $settings['enabled']);
        self::assertNotContains('laravel-best-practices', $settings['enabled']);
        self::assertNotContains('form-requests', $settings['enabled']);
    }

    public function test_guidance_and_discovery_use_composer_roots_without_artisan_or_laravel(): void
    {
        $this->register(['actions', 'value-objects']);
        $context = new RepositoryContext($this->root, $this->home);
        RepositoryContext::activate($context);
        $files = new Filesystem;
        $resources = new ArchitectureResources(dirname(__DIR__, 3), $this->root, $files);
        $guideline = $resources->fullGuideline([Architecture::Actions, Architecture::ValueObjects]);
        self::assertStringContainsString('lib/Actions', $guideline);
        self::assertStringContainsString('architecture-kit guard --changed --strict', $guideline);
        self::assertStringNotContainsString('php artisan', $guideline);
        self::assertStringNotContainsString('app/', $guideline);
        self::assertStringNotContainsString('Eloquent/Value Objects', $guideline);
        self::assertStringContainsString('Placement: `lib/Actions`', $resources->architectureGuideline(Architecture::Actions, [Architecture::Actions]));
        $skill = $resources->skills([Architecture::Actions])['actions']->contents;
        self::assertStringContainsString('final class', $skill);
        self::assertStringNotContainsString('DB::transaction', $skill);
        mkdir($this->root.'/lib');
        file_put_contents($this->root.'/lib/Example.php', '<?php namespace Example\\Payments; class Example {}');
        $settings = DiscoverySettings::load($files, $this->root);
        self::assertSame(['lib'], $settings->scope->directories);
        self::assertSame(['lib/Example.php'], array_keys((new ProjectGraphLoader($files, $this->root, $settings->scope, sourceOnly: true))->files()));
    }

    public function test_laravel_package_uses_framework_guidance_and_its_own_namespace(): void
    {
        $composer = ProjectLayout::read($this->root)->composer;
        $composer['require']['illuminate/database'] = '^12.0';
        file_put_contents($this->root.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
        $this->register(['actions', 'form-requests']);
        $context = new RepositoryContext($this->root, $this->home);
        RepositoryContext::activate($context);
        Host::create($this->home);
        $files = new Filesystem;
        $resources = new ArchitectureResources(dirname(__DIR__, 3), $this->root, $files);
        self::assertStringContainsString('Eloquent', $resources->architectureGuideline(Architecture::Actions, [Architecture::Actions]));
        $scaffolder = new Scaffolder($files, $this->root, new ArchitectureCatalog($files, $this->root));
        $plan = $scaffolder->plan('form-requests', 'ChargePayment', [Architecture::FormRequests]);
        self::assertSame('Example\\Payments\\Http\\Requests', $plan->namespace);
        $scaffolder->write($plan);
        self::assertSame(0, StandaloneAudit::run($context)->errors());
        self::assertFileDoesNotExist($this->root.'/artisan');
    }

    public function test_package_ports_are_audited_and_mirrors_use_only_production_roots(): void
    {
        $registration = $this->register(['ports-and-adapters', 'data-objects']);
        (new StateStore(dirname($registration['config'])))->update('default', static fn (array $settings): array => [...$settings,
            'audit' => [...($settings['audit'] ?? []), 'classification' => ['roles' => [
                ['path' => 'lib/Wiring', 'role' => 'composition'],
                ['path' => 'lib/Domain/Boundaries', 'role' => 'port', 'kind' => 'port'],
            ]]]]);
        $this->sources([
            'lib/Domain/Boundaries/BillingInterface.php' => '<?php namespace Example\\Payments\\Domain\\Boundaries; use GuzzleHttp\\Client; interface BillingInterface { public function pay(array $payload): Client; }',
            'lib/Services/Billing.php' => '<?php namespace Example\\Payments\\Services; class Billing {}',
            'tests/Billing.php' => '<?php class Billing {}',
            'lib/Tests/Billing.php' => '<?php class Billing {}',
            'lib/Providers/PaymentsProvider.php' => '<?php namespace Example\\Payments\\Providers; class PaymentsProvider { public function boot() { app(BillingInterface::class); } }',
            'lib/Wiring/Bootstrap.php' => '<?php namespace Example\\Payments\\Wiring; class Bootstrap { public function boot() { app(BillingInterface::class); } }',
        ]);
        RepositoryContext::activate($context = new RepositoryContext($this->root, $this->home));
        Host::create($this->home);
        $audit = StandaloneAudit::run($context);
        $port = $this->messages($audit, 'lib/Domain/Boundaries/BillingInterface.php', 'ports-and-adapters');
        self::assertStringContainsString('must not expose provider', $port);
        self::assertStringContainsString('must not use raw arrays', $port);
        self::assertStringContainsString('mirror a single local implementation', $port);
        self::assertSame('', $this->messages($audit, 'lib/Providers/PaymentsProvider.php', 'ports-and-adapters'));
        self::assertSame('', $this->messages($audit, 'lib/Wiring/Bootstrap.php', 'ports-and-adapters'));
    }

    public function test_package_enum_lookup_honours_placement_and_refreshes_on_the_next_audit(): void
    {
        $registration = $this->register(['enums']);
        (new StateStore(dirname($registration['config'])))->update('default', static fn (array $settings): array => [...$settings,
            'placements' => ['enums' => ['path' => 'lib/Domain/States', 'namespace' => 'Example\\Payments\\Domain\\States']]]);
        $this->sources([
            'lib/Models/Order.php' => '<?php namespace Example\\Payments\\Models; class Order { protected $fillable = ["status", "type"]; }',
            'lib/Domain/States/OrderStatus.php' => '<?php namespace Example\\Payments\\Domain\\States; enum OrderStatus: string { case Paid = "paid"; }',
            'lib/Domain/States/OrderType.php' => '<?php namespace Example\\Payments\\Domain\\States; class OrderType {}',
            'lib/Models/Payment.php' => '<?php namespace Example\\Payments\\Models; class Payment { protected $fillable = ["status"]; }',
            'lib/Enums/PaymentStatus.php' => '<?php namespace Example\\Payments\\Enums; enum PaymentStatus: string { case Paid = "paid"; }',
        ]);
        RepositoryContext::activate($context = new RepositoryContext($this->root, $this->home));
        Host::create($this->home);
        self::assertStringContainsString('add an Eloquent enum cast', $this->messages(StandaloneAudit::run($context), 'lib/Models/Order.php', 'enums'));
        self::assertStringNotContainsString('OrderType', $this->messages(StandaloneAudit::run($context), 'lib/Models/Order.php', 'enums'));
        self::assertStringContainsString('add an Eloquent enum cast', $this->messages(StandaloneAudit::run($context), 'lib/Models/Payment.php', 'enums'));
        unlink($this->root.'/lib/Domain/States/OrderStatus.php');
        self::assertSame('', $this->messages(StandaloneAudit::run($context), 'lib/Models/Order.php', 'enums'));
    }

    public function test_laravel_package_adapter_and_service_locator_checks_use_package_namespaces(): void
    {
        $composer = ProjectLayout::read($this->root)->composer;
        $composer['require']['illuminate/database'] = '^12.0';
        file_put_contents($this->root.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
        $this->register(['saloon', 'eloquent-lifecycle']);
        $this->sources([
            'lib/Http/Controllers/ChargeController.php' => '<?php namespace Example\\Payments\\Http\\Controllers; use Example\\Payments\\Http\\Integrations\\PaymentConnector; class ChargeController { public function run() { app(PaymentConnector::class); $connector = new PaymentConnector; $connector->send(); } }',
            'lib/Http/Resources/ChargeResource.php' => '<?php namespace Example\\Payments\\Http\\Resources; class ChargeResource { public function run() { app(Clock::class); } }',
            'lib/Providers/PaymentsProvider.php' => '<?php namespace Example\\Payments\\Providers; use Example\\Payments\\Models\\Order; class PaymentsProvider { public function boot() { Order::observe(OrderObserver::class); } }',
            'lib/Models/Order.php' => '<?php namespace Example\\Payments\\Models; class Order {}',
        ]);
        RepositoryContext::activate($context = new RepositoryContext($this->root, $this->home));
        Host::create($this->home);
        $audit = StandaloneAudit::run($context);
        $controller = $this->messages($audit, 'lib/Http/Controllers/ChargeController.php', 'saloon');
        self::assertStringContainsString('must not import integration classes', $controller);
        self::assertStringContainsString('must not instantiate Saloon Connectors', $controller);
        self::assertStringContainsString('must not send Saloon requests', $controller);
        self::assertStringContainsString('Avoid service locator', $this->messages($audit, 'lib/Http/Controllers/ChargeController.php', 'service-locator'));
        self::assertStringContainsString('Avoid service locator', $this->messages($audit, 'lib/Http/Resources/ChargeResource.php', 'service-locator'));
        self::assertStringContainsString('Register observers with', $this->messages($audit, 'lib/Providers/PaymentsProvider.php', 'eloquent-lifecycle'));
    }

    /** @return array<string, array{string}> */
    public static function sourceRoots(): array
    {
        return ['plain' => ['lib/'], 'relative' => ['./lib/']];
    }

    #[DataProvider('sourceRoots')]
    public function test_custom_laravel_package_placements_and_model_identity_are_respected(string $sourceRoot): void
    {
        $composer = ProjectLayout::read($this->root)->composer;
        $composer['require']['illuminate/database'] = '^12.0';
        $composer['autoload']['psr-4']['Example\\Payments\\'] = $sourceRoot;
        file_put_contents($this->root.'/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
        $registration = $this->register(['saloon', 'eloquent-lifecycle', 'enums']);
        (new StateStore(dirname($registration['config'])))->update('default', static fn (array $settings): array => [...$settings,
            'placements' => [
                'thin-controllers' => ['path' => 'lib/Web', 'namespace' => 'Example\\Payments\\Web'],
                'api-resources' => ['path' => 'lib/Output', 'namespace' => 'Example\\Payments\\Output'],
            ],
            'audit' => [...($settings['audit'] ?? []), 'classification' => ['roles' => [
                ['path' => 'lib/Clients', 'role' => 'infrastructure'],
                ['path' => 'lib/Wiring', 'kind' => 'provider', 'role' => 'composition'],
                ['namespace' => 'Example\\Payments\\Records', 'kind' => 'model'],
                ['namespace' => 'Example\\Payments\\OtherRecords', 'kind' => 'model'],
            ]]]]);
        $this->sources([
            'lib/Web/Charge.php' => '<?php namespace Example\\Payments\\Web; use Example\\Payments\\Clients\\PaymentConnector; class Charge { public function run() { app(PaymentConnector::class); $c = new PaymentConnector; $c->send(); } }',
            'lib/Clients/PaymentConnector.php' => '<?php namespace Example\\Payments\\Clients; class PaymentConnector {}',
            'lib/Output/Charge.php' => '<?php namespace Example\\Payments\\Output; class Charge { public function run() { app(Clock::class); } }',
            'lib/Records/Order.php' => '<?php namespace Example\\Payments\\Records; use Illuminate\\Database\\Eloquent\\Attributes\\ObservedBy as Observed; #[Observed([])] class Order {}',
            'lib/OtherRecords/Order.php' => '<?php namespace Example\\Payments\\OtherRecords; class Order {}',
            'lib/Wiring/Boot.php' => <<<'PHP'
<?php
namespace Example\Payments\Wiring;
use Example\Payments\Records\Order as ObservedOrder;
use Example\Payments\OtherRecords\Order as PlainOrder;
class Boot {
    public function boot() {
        ObservedOrder::observe(Observer::class);
        PlainOrder::observe(Observer::class);
    }
}
PHP,
        ]);
        RepositoryContext::activate($context = new RepositoryContext($this->root, $this->home));
        Host::create($this->home);
        $audit = StandaloneAudit::run($context);
        self::assertStringContainsString('must not import integration classes', $this->messages($audit, 'lib/Web/Charge.php', 'saloon'));
        self::assertStringContainsString('must not send Saloon requests', $this->messages($audit, 'lib/Web/Charge.php', 'saloon'));
        self::assertStringContainsString('Avoid service locator', $this->messages($audit, 'lib/Web/Charge.php', 'service-locator'));
        self::assertStringContainsString('Avoid service locator', $this->messages($audit, 'lib/Output/Charge.php', 'service-locator'));
        $registrations = array_values(array_filter($audit->findings, static fn ($finding) => $finding->path === 'lib/Wiring/Boot.php' && str_contains($finding->message, 'Register observers with')));
        self::assertCount(1, $registrations);
        self::assertSame(8, $registrations[0]->line);
        file_put_contents($this->root.'/lib/Records/Order.php', '<?php namespace Example\\Payments\\Records; class Order {}');
        self::assertSame(2, substr_count($this->messages(StandaloneAudit::run($context), 'lib/Wiring/Boot.php', 'eloquent-lifecycle'), 'Register observers with'));
    }

    /** @return array<string, array{bool}> */
    public static function compositionMappings(): array
    {
        return ['roles only' => [false], 'roles and kinds' => [true]];
    }

    #[DataProvider('compositionMappings')]
    public function test_composition_declaration_does_not_disable_a_port_in_the_same_file(bool $declareKinds): void
    {
        $registration = $this->register(['ports-and-adapters', 'data-objects']);
        $roles = [
            ['namespace' => 'Example\\Payments\\Wiring', 'role' => 'composition'],
            ['namespace' => 'Example\\Payments\\Contracts', 'role' => 'port'],
        ];
        if ($declareKinds) {
            $roles[0]['kind'] = 'provider';
            $roles[1]['kind'] = 'port';
        }
        (new StateStore(dirname($registration['config'])))->update('default', static fn (array $settings): array => [...$settings,
            'audit' => [...($settings['audit'] ?? []), 'classification' => ['roles' => $roles]]]);
        $path = 'lib/Mixed.php';
        $this->sources([$path => <<<'PHP'
<?php
namespace Example\Payments\Wiring {
    class Bootstrap {
        public function boot() { app(BillingInterface::class); }
    }
}
namespace Example\Payments\Contracts {
    interface MixedBoundary {
        public function pay(array $payload): array;
    }
}
PHP]);
        RepositoryContext::activate($context = new RepositoryContext($this->root, $this->home));
        Host::create($this->home);
        $messages = $this->messages(StandaloneAudit::run($context), $path, 'ports-and-adapters');
        self::assertStringContainsString('must not use raw arrays', $messages);
        self::assertStringNotContainsString('Do not resolve Ports through service locator', $messages);
    }

    public function test_explicit_audit_roots_outside_composer_have_the_same_port_guidance_and_enforcement(): void
    {
        $registration = $this->register(['ports-and-adapters', 'data-objects']);
        (new StateStore(dirname($registration['config'])))->update('default', static fn (array $settings): array => [...$settings,
            'audit' => [...($settings['audit'] ?? []), 'paths' => ['custom']]]);
        $path = 'custom/Contracts/PaymentGateway.php';
        $this->sources([$path => '<?php namespace Example\\Custom\\Contracts; interface PaymentGateway { public function pay(array $payload): array; }']);
        RepositoryContext::activate($context = new RepositoryContext($this->root, $this->home));
        Host::create($this->home);
        $files = new Filesystem;
        $scope = (new ArchitectureConfig($context->configPath, $files))->auditScope();
        $guidance = new FileGuidance($files, $this->root, new ArchitectureCatalog($files, $this->root), $scope);
        self::assertContains('ports-and-adapters', $guidance->for($path, [Architecture::PortsAndAdapters, Architecture::DataObjects], new CustomRuleSet)['rules']);
        self::assertStringContainsString('must not use raw arrays', $this->messages(StandaloneAudit::run($context), $path, 'ports-and-adapters'));
    }

    /** @param array<string, string> $sources */
    private function sources(array $sources): void
    {
        foreach ($sources as $path => $source) {
            (new Filesystem)->ensureDirectoryExists(dirname($this->root.'/'.$path));
            file_put_contents($this->root.'/'.$path, $source);
        }
    }

    private function messages(ApplicationAuditResult $audit, string $path, string $rule): string
    {
        return implode("\n", array_map(static fn ($finding) => $finding->message,
            array_filter($audit->findings, static fn ($finding) => $finding->path === $path && $finding->rule === $rule)));
    }

    public function test_scaffolding_rejects_symlinked_package_roots_and_a_file_created_after_planning(): void
    {
        $this->register(['actions']);
        RepositoryContext::activate(new RepositoryContext($this->root, $this->home));
        $files = new Filesystem;
        $scaffolder = new Scaffolder($files, $this->root, new ArchitectureCatalog($files, $this->root));
        $plan = $scaffolder->plan('actions', 'ChargePayment', [Architecture::Actions]);
        mkdir($this->temporary.'/outside');
        symlink($this->temporary.'/outside', $this->root.'/lib');
        try {
            $scaffolder->write($plan);
            self::fail('A symlinked destination must be rejected.');
        } catch (ScaffoldException $error) {
            self::assertStringContainsString('symlink', $error->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->temporary.'/outside/Actions');
        unlink($this->root.'/lib');
        mkdir($this->root.'/lib/Actions', 0700, true);
        file_put_contents($this->root.'/'.$plan->paths()[0], 'existing work');
        try {
            $scaffolder->write($plan);
            self::fail('A file created after planning must not be overwritten.');
        } catch (ScaffoldException) {
            self::assertSame('existing work', file_get_contents($this->root.'/'.$plan->paths()[0]));
        }
    }
}
