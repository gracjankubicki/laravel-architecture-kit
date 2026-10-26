<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisMode;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisSession;
use GracjanKubicki\ArchitectureKit\Analysis\ControlPolicy;
use GracjanKubicki\ArchitectureKit\Analysis\MagoAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Audit\AuditRule;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\PortableAuditRule;
use GracjanKubicki\ArchitectureKit\Context\GraphSource;
use GracjanKubicki\ArchitectureKit\Guard\ArchitectureGuard;
use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use GracjanKubicki\ArchitectureKit\Standalone\AnalysisConsent;
use GracjanKubicki\ArchitectureKit\Standalone\Host;
use GracjanKubicki\ArchitectureKit\Standalone\Projects;
use GracjanKubicki\ArchitectureKit\Standalone\RepositoryContext;
use GracjanKubicki\ArchitectureKit\Standalone\SessionBinding;
use GracjanKubicki\ArchitectureKit\Standalone\StandaloneAudit;
use GracjanKubicki\ArchitectureKit\Standalone\StateStore;
use GracjanKubicki\ArchitectureKit\Standalone\TaskControl;
use GracjanKubicki\ArchitectureKit\Tests\Support\EngineFixture;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AdvisoryTaskControlTest extends TestCase
{
    private string $directory;

    private string $root;

    private string $home;

    private string $profile;

    protected function setUp(): void
    {
        mkdir($this->directory = sys_get_temp_dir().'/kit-advisory-'.bin2hex(random_bytes(8)), 0700);
        mkdir($this->root = $this->directory.'/repo');
        mkdir($this->root.'/app');
        $this->home = $this->directory.'/home';
        file_put_contents($this->root.'/composer.json', '{"type":"project","require":{"php":"^8.3"}}');
        $registration = (new Projects($this->home))->register($this->root, ['actions']);
        $this->profile = $registration['config'];
        $this->settings(['analysis' => ['engine' => EngineFixture::selected()]]);
        if (EngineFixture::selected() === 'mago') {
            EngineFixture::install($this->home);
        }
        Host::create($this->home);
        AdvisoryPortableRule::$throw = false;
        AdvisoryPortableRule::$marker = null;
    }

    protected function tearDown(): void
    {
        RepositoryContext::activate(null);
        (new Filesystem)->deleteDirectory($this->directory);
    }

    public function test_basic_error_is_false_while_advisory_and_enforce_choose_different_reactions(): void
    {
        $this->source('Target.php', 'class Target { public function run() {} }');
        $this->source('Caller.php', 'class Caller { public function call(Target $target) { $target->run(); } }');
        $context = $this->context();
        $stages = [];
        AnalysisRuntime::activate($context->analysis()->withStageObserver(function ($stage) use (&$stages): void {
            $stages[] = $stage;
        }));
        $control = new TaskControl($context);
        $begin = $control->begin('A');
        self::assertSame([], $stages);
        self::assertSame('basic', $begin['analysis']['mode']);
        self::assertSame(['policy' => 'advisory', 'blocking' => false], $begin['control']);
        $this->source('Target.php', 'class Target { public function run($required) {} }');
        $failed = $control->check('A');
        self::assertSame('fail', $failed['status']);
        self::assertFalse($failed['ok']);
        self::assertGreaterThan(0, $failed['new_errors']);
        self::assertFalse($failed['control']['blocking']);
        $enforced = (new TaskControl($context, ControlPolicy::Enforce))->check('A');
        self::assertFalse($enforced['ok']);
        self::assertTrue($enforced['control']['blocking']);
        self::assertSame($begin['begin_sources'], $enforced['begin_sources']);
        self::assertSame([], $stages);
        $this->source('Target.php', 'class Target { public function run($required = null) {} }');
        self::assertSame('pass', $control->check('A')['status']);
    }

    public function test_strict_changes_warning_evaluation_without_enabling_enforce(): void
    {
        $this->settings(['enabled' => ['actions'], 'audit' => ['missing_test' => 'warn']]);
        $context = $this->context();
        $control = new TaskControl($context);
        $control->begin('A');
        $this->source('Worker.php', 'class Worker { public function run() {} }');
        self::assertTrue($control->check('A')['ok']);
        $strict = (new TaskControl($context, strict: true))->check('A');
        self::assertFalse($strict['ok']);
        self::assertGreaterThan(0, $strict['warnings']);
        self::assertFalse($strict['control']['blocking']);
    }

    public function test_warming_a_partial_basic_map_does_not_begin_a_task_or_refresh_existing_beginning(): void
    {
        $this->source('Target.php', 'class Target { public function run() {} }');
        $context = $this->context();
        $control = new TaskControl($context);
        $begin = $control->begin('A');
        $state = $context->storage.'/tasks/'.hash('sha256', 'A').'.json';
        $before = hash_file('sha256', $state);
        $this->source('Broken.php', 'class Broken { invalid');
        $map = (new GraphSource(new Filesystem, $this->root))->load();
        self::assertFalse($map['analysis']['checks_complete']);
        self::assertSame($before, hash_file('sha256', $state));
        self::assertSame($begin['begin_sources'], $control->status('A')['begin_sources']);
        self::assertSame('missing', $control->status('missing')['status']);
        $missing = $control->check('missing');
        self::assertFalse($missing['ok']);
        self::assertFalse($missing['control']['blocking']);
        self::assertFileDoesNotExist($context->storage.'/tasks/'.hash('sha256', 'missing').'.json');
    }

    public function test_retry_resume_grant_and_old_errors_never_refresh_the_beginning(): void
    {
        $this->source('Target.php', 'class Target { public function run($required) {} }');
        $this->source('Caller.php', 'class Caller { public function call(Target $target) { $target->run(); } }');
        $context = $this->context();
        $control = new TaskControl($context);
        $begin = $control->begin('A');
        self::assertNotEmpty($begin['begin_errors']);
        $this->source('NewCaller.php', 'class NewCaller { public function call(Target $target) { $target->run(); } }');
        self::assertFalse($control->check('A')['ok']);
        (new AnalysisConsent($context))->grantDeep('A');
        $resumed = new TaskControl($this->context());
        self::assertSame($begin['begin_sources'], $resumed->begin('A')['begin_sources']);
        self::assertSame($begin['begin_errors'], $resumed->status('A')['begin_errors']);
        $this->source('Target.php', 'class Target { public function run($required = null) {} }');
        $fixed = $resumed->check('A');
        self::assertTrue($fixed['ok']);
        self::assertSame($begin['begin_errors'], $fixed['begin_errors']);
    }

    public function test_missing_or_corrupt_state_and_failed_begin_remain_unverified(): void
    {
        $context = $this->context();
        $control = new TaskControl($context);
        $missing = $control->check('missing');
        self::assertFalse($missing['ok']);
        self::assertFalse($missing['control']['blocking']);
        self::assertFalse($missing['analysis']['checks_complete']);
        self::assertFileDoesNotExist($context->storage.'/tasks/'.hash('sha256', 'missing').'.json');
        self::assertSame('missing', $control->status('missing')['status']);
        self::assertSame('started', $control->begin('missing')['status']);
        self::assertTrue($control->check('missing')['ok']);
        file_put_contents($context->storage.'/tasks/'.hash('sha256', 'corrupt').'.json', '{not-json');
        $corrupt = $control->check('corrupt');
        self::assertSame('blocked', $corrupt['status']);
        self::assertFalse($corrupt['control']['blocking']);
        $this->source('Broken.php', 'class Broken { invalid');
        $blocked = $control->begin('broken');
        self::assertSame('blocked', $blocked['status']);
        unlink($this->root.'/app/Broken.php');
        self::assertSame('blocked', $control->begin('broken')['status']);
        self::assertArrayNotHasKey('begin_errors', $control->status('broken'));
        self::assertTrue((new TaskControl($context, ControlPolicy::Enforce))->check('corrupt')['control']['blocking']);
    }

    public function test_valid_json_without_evidence_cannot_claim_pass_and_enforce_applies_to_faults(): void
    {
        $this->settings(['control' => ['policy' => 'enforce']]);
        $context = $this->context();
        $control = new TaskControl($context);
        $control->begin('A');
        $control->check('A');
        $store = new StateStore($context->storage.'/tasks', $context->home);
        $store->update(hash('sha256', 'A'), static function ($saved) {
            unset($saved['begin_errors']);

            return $saved;
        });
        $broken = $control->status('A');
        self::assertSame('blocked', $broken['status']);
        self::assertFalse($broken['ok']);
        self::assertTrue($broken['control']['blocking']);
        self::assertFalse($broken['analysis']['checks_complete']);
    }

    public function test_pass_becomes_stale_on_equal_stat_edit_and_configuration_drift(): void
    {
        $this->source('One.php', 'class One {}');
        $context = $this->context();
        $control = new TaskControl($context);
        $begin = $control->begin('A');
        self::assertTrue($control->check('A')['ok']);
        self::assertTrue($control->begin('A')['ok']);
        $path = $this->root.'/app/One.php';
        $mtime = filemtime($path);
        file_put_contents($path, str_replace('One', 'Two', file_get_contents($path)));
        touch($path, $mtime);
        $stale = $control->begin('A');
        self::assertSame($control->status('A'), $stale);
        self::assertSame($begin['begin_sources'], $stale['begin_sources']);
        self::assertSame($begin['begin_errors'], $stale['begin_errors']);
        $enforced = (new TaskControl($context, ControlPolicy::Enforce))->begin('A');
        self::assertFalse($enforced['ok']);
        self::assertTrue($enforced['control']['blocking']);
        self::assertSame('stale', $stale['status']);
        self::assertFalse($stale['ok']);
        self::assertFalse($stale['control']['blocking']);
        self::assertTrue($control->check('A')['ok']);
        $this->settings(['enabled' => ['services']]);
        $driftBegin = $control->begin('A');
        self::assertFalse($driftBegin['ok']);
        self::assertFalse($driftBegin['analysis']['checks_complete']);
        self::assertSame($begin['begin_sources'], $driftBegin['begin_sources']);
        self::assertSame($begin['begin_errors'], $driftBegin['begin_errors']);
        self::assertTrue((new TaskControl($context, ControlPolicy::Enforce))->begin('A')['control']['blocking']);
        $this->settings(['enabled' => ['services'], 'control' => ['policy' => 'enforce']]);
        self::assertTrue((new TaskControl($this->context()))->begin('A')['control']['blocking']);
        $drift = $control->check('A');
        self::assertSame('blocked', $drift['status']);
        self::assertFalse($drift['analysis']['checks_complete']);
        self::assertFalse($drift['control']['blocking']);
    }

    public function test_grant_changes_neither_basic_identity_nor_runtime_permissions_and_enables_real_deep(): void
    {
        $this->source('Factory.php', 'function factory() { return fn () => 1; }');
        $context = $this->context();
        $control = new TaskControl($context);
        $begin = $control->begin('A');
        $fingerprint = $context->fingerprint();
        try {
            StandaloneAudit::run($context, mode: AnalysisMode::Deep, task: 'A');
            self::fail('Deep without consent ran.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('consent', $error->getMessage());
        }
        (new AnalysisConsent($context))->grantDeep('A');
        self::assertSame($fingerprint, $context->fingerprint());
        self::assertSame($begin, $control->begin('A'));
        self::assertFalse($context->execute);
        self::assertFalse($context->runtimeRoutes);
        $stages = [];
        AnalysisRuntime::activate($context->analysis()->withStageObserver(function ($stage) use (&$stages): void {
            $stages[] = $stage;
        }));
        $deep = StandaloneAudit::run($context, mode: AnalysisMode::Deep, task: 'A');
        self::assertSame('deep', $deep->analysis()['mode']);
        self::assertContains('deep.controller-read', $stages);
        self::assertSame($fingerprint, $context->fingerprint());
        self::assertFalse((new AnalysisConsent($context))->hasDeep('B'));
    }

    public function test_skip_confirmation_and_later_checks_never_report_pass(): void
    {
        $control = new TaskControl($this->context());
        $control->begin('A');
        $denied = $control->skip('A', false);
        self::assertFalse($denied['ok']);
        self::assertSame('started', $control->status('A')['status']);
        $skipped = $control->skip('A', true);
        self::assertSame('skipped', $skipped['status']);
        self::assertFalse($skipped['ok']);
        self::assertFalse($skipped['analysis']['checks_complete']);
        self::assertSame('skipped', $control->check('A')['status']);
        self::assertSame('skipped', $control->begin('A')['status']);
    }

    public function test_advisory_session_binding_preserves_the_previous_unverified_task(): void
    {
        $context = $this->context();
        $control = new TaskControl($context);
        $begin = $control->begin('A');
        $control->begin('B');
        SessionBinding::bind($context, 'codex', 'session', 'A');
        self::assertSame('B', SessionBinding::bind($context, 'codex', 'session', 'B')['task']);
        self::assertSame($begin['begin_sources'], $control->status('A')['begin_sources']);
    }

    public function test_custom_rules_keep_permissions_and_failures_are_advisory(): void
    {
        $this->source('One.php', 'class One {}');
        $this->settings(['rules' => [AdvisoryPortableRule::class]]);
        $context = $this->context();
        (new AnalysisConsent($context))->grantDeep('A');
        AdvisoryPortableRule::$marker = $this->directory.'/custom-marker';
        $denied = (new TaskControl($context))->begin('A');
        self::assertFalse($denied['ok']);
        self::assertFileDoesNotExist(AdvisoryPortableRule::$marker);
        $allowed = $this->context(execute: true);
        $control = new TaskControl($allowed);
        self::assertSame('started', $control->begin('allowed')['status']);
        self::assertFileExists(AdvisoryPortableRule::$marker);
        AdvisoryPortableRule::$throw = true;
        $failed = $control->check('allowed');
        self::assertSame('blocked', $failed['status']);
        self::assertFalse($failed['control']['blocking']);
        self::assertFalse($failed['analysis']['checks_complete']);
    }

    public function test_real_engine_timeout_is_unverified_without_php_fallback(): void
    {
        $this->source('One.php', 'class One {}');
        $context = $this->context();
        $binary = $this->directory.'/slow-engine';
        $marker = $this->directory.'/request-received';
        $program = <<<'CODE'
#!/usr/bin/env php
<?php
if (($argv[1] ?? '') === '--version') {
    echo json_encode(['engine'=>'mago','version'=>'__ENGINE_VERSION__','mago_version'=>'1.57.0','schema'=>1,'capabilities'=>['syntax-facts','syntax-tree-v1']]);
    exit;
}
fgets(STDIN);
file_put_contents(__DIR__.'/request-received', 'request received');
usleep(2000000);
CODE;
        file_put_contents($binary, str_replace('__ENGINE_VERSION__', MagoAnalysisEngine::VERSION, $program));
        chmod($binary, 0700);
        $engine = new MagoAnalysisEngine($binary, timeout: 0.5);
        AnalysisRuntime::activate(new AnalysisSession($engine, '8.5'));
        $start = microtime(true);
        $result = (new TaskControl($context))->begin('timeout');
        self::assertFalse($result['ok']);
        self::assertSame('blocked', $result['status']);
        self::assertFalse($result['control']['blocking']);
        self::assertFileExists($marker);
        self::assertGreaterThanOrEqual(0.45, microtime(true) - $start);
        self::assertStringContainsString('timed out', strtolower($result['reason']));
        self::assertFalse($result['analysis']['checks_complete']);
    }

    public function test_legacy_rule_works_with_explicit_php_without_a_new_interface(): void
    {
        $this->source('One.php', 'class One {}');
        $this->settings(['analysis' => ['engine' => 'php'], 'rules' => [AdvisoryLegacyRule::class]]);
        $context = $this->context(execute: true);
        self::assertSame('php', $context->analysis()->engine->engine);
        $control = new TaskControl($context);
        self::assertSame('started', $control->begin('legacy')['status']);
        self::assertTrue($control->check('legacy')['ok']);
        if (EngineFixture::selected() === 'mago') {
            $this->settings(['analysis' => ['engine' => 'mago']]);
            $mago = $this->context(execute: true);
            $failed = (new TaskControl($mago))->begin('legacy-mago');
            self::assertFalse($failed['ok']);
            self::assertSame('mago', $mago->analysis()->engine->engine);
            self::assertStringContainsString('requires the PHP engine', $failed['reason']);
            self::assertFalse($failed['control']['blocking']);
        }
    }

    public function test_deep_grant_does_not_boot_the_lab_but_separate_runtime_permission_does(): void
    {
        $files = new Filesystem;
        foreach (['bootstrap/cache', 'routes', 'vendor', 'storage/framework/views', 'storage/logs'] as $path) {
            $files->ensureDirectoryExists($this->root.'/'.$path);
        }
        $composer = json_decode(file_get_contents($this->root.'/composer.json'), true);
        $composer['autoload']['psr-4']['App\\'] = 'app/';
        file_put_contents($this->root.'/composer.json', json_encode($composer));
        file_put_contents($this->root.'/vendor/autoload.php', '<?php require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).';');
        $bootstrapMarker = $this->directory.'/bootstrap-marker';
        $endpointMarker = $this->directory.'/endpoint-marker';
        file_put_contents($this->root.'/bootstrap/app.php', '<?php file_put_contents('.var_export($bootstrapMarker, true).', "bootstrap"); return Illuminate\\Foundation\\Application::configure(basePath: dirname(__DIR__))->withRouting(web: dirname(__DIR__)."/routes/web.php")->withMiddleware(function (Illuminate\\Foundation\\Configuration\\Middleware $middleware): void {})->withExceptions()->create();');
        file_put_contents($this->root.'/routes/web.php', '<?php Illuminate\\Support\\Facades\\Route::get("/lab", function () { file_put_contents('.var_export($endpointMarker, true).', "endpoint"); });');
        $context = $this->context();
        (new AnalysisConsent($context))->grantDeep('A');
        StandaloneAudit::run($context, mode: AnalysisMode::Deep, task: 'A');
        self::assertFileDoesNotExist($bootstrapMarker);
        self::assertFileDoesNotExist($endpointMarker);
        $runtime = $this->context(runtimeRoutes: true);
        self::assertFalse($runtime->execute);
        $result = StandaloneAudit::run($runtime, mode: AnalysisMode::Deep, task: 'A');
        self::assertFalse($result->hasAnalysisFailure());
        self::assertFileExists($bootstrapMarker);
        self::assertFileDoesNotExist($endpointMarker);
    }

    public function test_missing_mago_engine_does_not_fall_back_to_php(): void
    {
        $home = $this->directory.'/without-engine';
        (new Projects($home))->register($this->root, ['actions']);
        $context = new RepositoryContext(realpath($this->root), $home);
        RepositoryContext::activate($context);
        AnalysisRuntime::activate(null);
        $result = (new TaskControl($context))->begin('missing-engine');
        self::assertFalse($result['ok']);
        self::assertSame('blocked', $result['status']);
        self::assertStringContainsString('Mago engine is not installed', $result['reason']);
        self::assertArrayNotHasKey('begin_errors', $result);
        self::assertFalse($result['control']['blocking']);
    }

    public function test_guard_and_agent_output_preserve_failure_under_both_policies(): void
    {
        $this->source('One.php', 'class One {}');
        $this->settings(['rules' => [AdvisoryPortableRule::class]]);
        $context = $this->context();
        $guard = new ArchitectureGuard(new Filesystem, dirname(__DIR__, 2), $this->root);
        $advisory = $guard->run(false, null, false);
        $enforce = $guard->run(false, null, true, policy: ControlPolicy::Enforce);
        self::assertFalse($advisory->ok());
        self::assertFalse($advisory->control()['blocking']);
        self::assertFalse($enforce->ok());
        self::assertTrue($enforce->control()['blocking']);
        $agent = new AgentOutput;
        $short = $agent->guard($advisory, limit: 0);
        $full = $agent->guard($advisory, full: true);
        self::assertFalse($short['ok']);
        self::assertFalse($short['analysis']['checks_complete']);
        self::assertSame($short['analysis']['checks_complete'], $full['analysis']['checks_complete']);
        self::assertFalse($agent->error('audit', 'failure')['control']['blocking']);
        self::assertTrue($agent->error('audit', 'failure', policy: ControlPolicy::Enforce)['control']['blocking']);
    }

    public function test_local_guard_preserves_configured_enforce_when_analysis_throws(): void
    {
        RepositoryContext::activate(null);
        mkdir($this->root.'/config');
        file_put_contents($this->root.'/config/architectures.php', "<?php return ['control' => ['policy' => 'enforce'], 'enabled' => ['actions']];");
        $files = new class extends Filesystem
        {
            public function files($directory, $hidden = false, array|string|int $depth = 0)
            {
                throw new RuntimeException('local discovery failed');
            }
        };
        $guard = new ArchitectureGuard($files, dirname(__DIR__, 2), $this->root);
        $result = $guard->run(false, null, false);
        self::assertFalse($result->ok());
        self::assertSame(['policy' => 'enforce', 'blocking' => true], $result->control());
        self::assertSame('local discovery failed', $result->audit->notices[0]->message);
    }

    private function context(bool $execute = false, bool $runtimeRoutes = false): RepositoryContext
    {
        $context = new RepositoryContext(realpath($this->root), $this->home, execute: $execute, runtimeRoutes: $runtimeRoutes);
        RepositoryContext::activate($context);
        AnalysisRuntime::activate($context->analysis());

        return $context;
    }

    private function settings(array $changes): void
    {
        $settings = json_decode(file_get_contents($this->profile), true);
        file_put_contents($this->profile, json_encode(array_replace($settings, $changes), JSON_THROW_ON_ERROR));
    }

    private function source(string $path, string $code): void
    {
        file_put_contents($this->root.'/app/'.$path, '<?php namespace App; '.$code);
    }
}

final class AdvisoryPortableRule implements PortableAuditRule
{
    public static bool $throw = false;

    public static ?string $marker = null;

    public function __construct()
    {
        if (self::$marker !== null) {
            file_put_contents(self::$marker, 'executed');
        }
    }

    public function supports(string $path, array $enabled): bool
    {
        return true;
    }

    public function check(FileContext $file): array
    {
        if (self::$throw) {
            throw new RuntimeException('Custom rule failed.');
        }

        return [];
    }
}

final class AdvisoryLegacyRule implements AuditRule
{
    public function supports(string $path, array $enabled): bool
    {
        return true;
    }

    public function check(FileContext $file): array
    {
        return [];
    }
}
