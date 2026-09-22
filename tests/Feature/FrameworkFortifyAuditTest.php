<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\MissingTestLevel;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteEntry;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class FrameworkFortifyAuditTest extends TestCase
{
    public function test_login_view_callback_is_checked_without_a_vendor_source_file(): void
    {
        $this->write('app/Models/Project.php', '<?php namespace App\Models; final class Project extends \Illuminate\Database\Eloquent\Model {}');
        $this->write('app/Providers/FortifyServiceProvider.php', <<<'PHP'
<?php namespace App\Providers;
final class FortifyServiceProvider {
    public function boot(): void {
        \Laravel\Fortify\Fortify::loginView(fn () => \Inertia\Inertia::render('Auth/Login', [
            'projects' => fn () => \App\Models\Project::query()->update([]),
        ]));
    }
}
PHP);

        $findings = $this->thinFindings($this->routes([
            new RouteEntry(['GET', 'HEAD'], 'login', class: 'Laravel\\Fortify\\Http\\Controllers\\AuthenticatedSessionController', method: 'create'),
        ]));

        $this->assertCount(1, $findings);
        $this->assertSame('S_MOVE_WRITE_TO_ACTION', $findings[0]->code);
        $this->assertSame('app/Providers/FortifyServiceProvider.php', $findings[0]->path);
    }

    public function test_registration_test_reaches_only_the_registered_create_action(): void
    {
        $this->fortifyActions();
        $this->write('tests/Feature/RegisterTest.php', "<?php it('registers', function () { \$this->post('/register'); });");

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [],
            changedOnly: false,
            missingTestLevel: MissingTestLevel::Warn,
            routes: $this->routes([
                new RouteEntry(['POST'], 'register', class: 'Laravel\\Fortify\\Http\\Controllers\\RegisteredUserController', method: 'store'),
            ]),
        );

        $missing = array_values(array_filter($result->findings, fn ($finding): bool => $finding->code === 'W_MISSING_TEST'));
        $paths = array_column($missing, 'path');
        $this->assertNotContains('app/Actions/CreateNewUser.php', $paths);
        $this->assertContains('app/Actions/ResetUserPassword.php', $paths);
    }

    public function test_login_get_does_not_credit_registration_action_and_standard_vendor_view_is_known(): void
    {
        $this->fortifyActions();
        $this->write('tests/Feature/LoginTest.php', "<?php it('shows login', function () { \$this->get('/login'); });");

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [],
            changedOnly: false,
            missingTestLevel: MissingTestLevel::Warn,
            routes: $this->routes([
                new RouteEntry(['GET', 'HEAD'], 'login', class: 'Laravel\\Fortify\\Http\\Controllers\\AuthenticatedSessionController', method: 'create'),
            ]),
        );

        $this->assertNotContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($result->findings, 'code'));
        $missingPaths = array_column(array_filter($result->findings, fn ($finding): bool => $finding->code === 'W_MISSING_TEST'), 'path');
        $this->assertContains('app/Actions/CreateNewUser.php', $missingPaths);
        $this->assertContains('app/Actions/ResetUserPassword.php', $missingPaths);
    }

    public function test_dynamic_authentication_pipeline_remains_incomplete(): void
    {
        $this->write('app/Providers/FortifyServiceProvider.php', <<<'PHP'
<?php namespace App\Providers;
final class FortifyServiceProvider {
    public function boot(): void { \Laravel\Fortify\Fortify::authenticateThrough($this->pipeline()); }
    private function pipeline(): callable { return fn () => []; }
}
PHP);
        $this->write('tests/Feature/LoginTest.php', "<?php it('logs in', function () { \$this->post('/login'); });");

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [],
            changedOnly: false,
            missingTestLevel: MissingTestLevel::Warn,
            routes: $this->routes([
                new RouteEntry(['POST'], 'login', class: 'Laravel\\Fortify\\Http\\Controllers\\AuthenticatedSessionController', method: 'store'),
            ]),
        );

        $this->assertContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($result->findings, 'code'));
    }

    public function test_static_authentication_pipeline_reaches_only_its_application_stage(): void
    {
        $this->write('app/Auth/CustomStage.php', '<?php namespace App\Auth; final class CustomStage { public function __invoke($request, $next): void {} }');
        $this->write('app/Auth/SecondStage.php', '<?php namespace App\Auth; final class SecondStage { public function __invoke($request, $next): void {} }');
        $this->write('app/Auth/UnusedStage.php', '<?php namespace App\Auth; final class UnusedStage { public function __invoke($request, $next): void {} }');
        $this->write('app/Providers/FortifyServiceProvider.php', <<<'PHP'
<?php namespace App\Providers;
final class FortifyServiceProvider {
    public function boot(): void {
        \Laravel\Fortify\Fortify::authenticateThrough(fn () => array_merge(
            [\App\Auth\CustomStage::class],
            [\App\Auth\SecondStage::class],
        ));
    }
}
PHP);
        $this->write('tests/Feature/LoginTest.php', "<?php it('logs in', function () { \$this->post('/login'); });");

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [],
            changedOnly: false,
            missingTestLevel: MissingTestLevel::Warn,
            routes: $this->routes([
                new RouteEntry(['POST'], 'login', class: 'Laravel\\Fortify\\Http\\Controllers\\AuthenticatedSessionController', method: 'store'),
            ]),
        );
        $missing = array_column(array_filter($result->findings, fn ($finding): bool => $finding->code === 'W_MISSING_TEST'), 'path');

        $this->assertNotContains('app/Auth/CustomStage.php', $missing);
        $this->assertNotContains('app/Auth/SecondStage.php', $missing);
        $this->assertContains('app/Auth/UnusedStage.php', $missing);
        $this->assertNotContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($result->findings, 'code'));
    }

    public function test_partly_dynamic_merged_authentication_pipeline_remains_incomplete(): void
    {
        $this->write('app/Auth/CustomStage.php', '<?php namespace App\Auth; final class CustomStage { public function __invoke($request, $next): void {} }');
        $this->write('app/Providers/FortifyServiceProvider.php', <<<'PHP'
<?php namespace App\Providers;
final class FortifyServiceProvider {
    public function boot(): void {
        \Laravel\Fortify\Fortify::authenticateThrough(fn () => array_merge($this->dynamicStages(), [\App\Auth\CustomStage::class]));
    }
    private function dynamicStages(): array { return []; }
}
PHP);
        $this->write('tests/Feature/LoginTest.php', "<?php it('logs in', function () { \$this->post('/login'); });");

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [],
            changedOnly: false,
            missingTestLevel: MissingTestLevel::Warn,
            routes: $this->routes([
                new RouteEntry(['POST'], 'login', class: 'Laravel\\Fortify\\Http\\Controllers\\AuthenticatedSessionController', method: 'store'),
            ]),
        );

        $this->assertContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($result->findings, 'code'));
    }

    private function fortifyActions(): void
    {
        $this->write('app/Actions/CreateNewUser.php', '<?php namespace App\Actions; final class CreateNewUser { public function create(array $input): object { return (object) $input; } }');
        $this->write('app/Actions/ResetUserPassword.php', '<?php namespace App\Actions; final class ResetUserPassword { public function reset(object $user, array $input): void {} }');
        $this->write('app/Providers/FortifyServiceProvider.php', <<<'PHP'
<?php namespace App\Providers;
final class FortifyServiceProvider {
    public function boot(): void {
        \Laravel\Fortify\Fortify::createUsersUsing(\App\Actions\CreateNewUser::class);
        \Laravel\Fortify\Fortify::resetUserPasswordsUsing(\App\Actions\ResetUserPassword::class);
    }
}
PHP);
    }

    /** @param list<RouteEntry> $entries */
    private function routes(array $entries): RouteMap
    {
        return new RouteMap(entries: $entries, context: [
            'status' => 'known',
            'providers' => ['App\\Providers\\FortifyServiceProvider'],
            'middleware' => [],
            'middlewareGroups' => [],
            'middlewareAliases' => [],
            'packageVersions' => ['laravel/fortify' => '1.x'],
        ]);
    }

    /** @return list<object> */
    private function thinFindings(RouteMap $routes): array
    {
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [Architecture::ThinControllers, Architecture::Actions],
            changedOnly: false,
            routes: $routes,
        );

        $items = array_values(array_filter($result->findings, fn ($finding): bool => $finding->rule === 'thin-controller'));
        foreach ($result->suggestions as $suggestion) {
            $items[] = (object) ['path' => $suggestion->path, 'line' => $suggestion->line, 'message' => $suggestion->message.' '.$suggestion->reason.' '.implode(' -> ', $suggestion->trace).' at '.$suggestion->path.':'.$suggestion->line, 'code' => $suggestion->code];
        }
        foreach ($result->notices as $notice) {
            $items[] = (object) ['path' => $notice->path, 'line' => $notice->line, 'message' => $notice->message, 'code' => $notice->code];
        }

        return $items;
    }

    private function write(string $path, string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $source);
    }
}
