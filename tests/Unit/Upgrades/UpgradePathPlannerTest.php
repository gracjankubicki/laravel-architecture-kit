<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Upgrades;

use GracjanKubicki\ArchitectureKit\Resources\GeneratedResourceMarker;
use GracjanKubicki\ArchitectureKit\Upgrades\UpgradePathPlanner;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

final class UpgradePathPlannerTest extends TestCase
{
    private string $packagePath;

    private string $projectPath;

    private Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();

        $root = sys_get_temp_dir().'/architecture-kit-upgrade-planner-'.uniqid('', true);
        $this->packagePath = $root.'/package';
        $this->projectPath = $root.'/project';
        $this->files = new Filesystem;
        $this->files->ensureDirectoryExists($this->packagePath);
        $this->files->ensureDirectoryExists($this->projectPath.'/config');
        $this->writeGuide('0.8', '0.9');
        $this->writeGuide('0.9', '0.10');
        $this->writeConfig(enabled: true);
        $this->writePackageState('^0.8', '0.8.1', '0.8.1');
        $this->writeGeneratedSkills();
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory(dirname($this->packagePath));

        parent::tearDown();
    }

    public function test_it_returns_the_full_route_but_activates_only_the_first_step(): void
    {
        $before = $this->snapshot();

        $plan = $this->planner()->plan('laravel/ai', '0.10');

        $this->assertTrue($plan->ok());
        $this->assertSame('ready', $plan->status);
        $this->assertSame(['ready', 'pending'], array_map(fn ($step): string => $step->status, $plan->route));
        $this->assertSame(['0.8', '0.9'], array_map(fn ($step): string => $step->guide->from->value, $plan->route));
        $this->assertSame('architecture-kit-upgrade-laravel-ai-0-8-to-0-9', $plan->activeStep()?->guide->name);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_it_activates_the_second_step_after_the_first_upgrade(): void
    {
        $this->writePackageState('^0.9', '0.9.4', '0.9.4');

        $plan = $this->planner()->plan('laravel/ai', '0.10');

        $this->assertSame('ready', $plan->status);
        $this->assertCount(1, $plan->route);
        $this->assertSame('architecture-kit-upgrade-laravel-ai-0-9-to-0-10', $plan->activeStep()?->guide->name);
    }

    public function test_it_reports_complete_when_the_project_is_already_on_the_target_line(): void
    {
        $this->writePackageState('^0.10', '0.10.1', '0.10.1');

        $plan = $this->planner()->plan('laravel/ai', '0.10');

        $this->assertTrue($plan->ok());
        $this->assertSame('complete', $plan->status);
        $this->assertSame([], $plan->route);
        $this->assertNull($plan->activeStep());
    }

    public function test_it_blocks_mismatched_locked_and_installed_versions(): void
    {
        $this->writePackageState('^0.8', '0.8.1', '0.8.2');

        $plan = $this->planner()->plan('laravel/ai', '0.10');

        $this->assertFalse($plan->ok());
        $this->assertSame('blocked', $plan->status);
        $this->assertStringContainsString('do not match', $plan->message);
    }

    public function test_it_blocks_a_constraint_that_does_not_allow_the_installed_version(): void
    {
        $this->writePackageState('^0.9', '0.8.1', '0.8.1');

        $plan = $this->planner()->plan('laravel/ai', '0.10');

        $this->assertSame('blocked', $plan->status);
        $this->assertStringContainsString('constraint', $plan->message);
    }

    public function test_it_blocks_a_package_locked_in_the_wrong_dependency_section(): void
    {
        $this->files->put($this->projectPath.'/composer.lock', json_encode([
            'packages' => [],
            'packages-dev' => [
                ['name' => 'laravel/ai', 'version' => '0.8.1'],
            ],
        ], JSON_THROW_ON_ERROR));

        $plan = $this->planner()->plan('laravel/ai', '0.10');

        $this->assertSame('blocked', $plan->status);
        $this->assertStringContainsString('packages-dev', $plan->message);
        $this->assertStringContainsString('packages', $plan->message);
    }

    public function test_it_blocks_missing_lock_vendor_and_transitive_state(): void
    {
        $this->files->delete($this->projectPath.'/composer.lock');
        $missingLock = $this->planner()->plan('laravel/ai', '0.10');

        $this->assertSame('blocked', $missingLock->status);
        $this->assertStringContainsString('composer.lock', $missingLock->message);

        $this->writePackageState('^0.8', '0.8.1', null);
        $missingVendor = $this->planner()->plan('laravel/ai', '0.10');

        $this->assertSame('blocked', $missingVendor->status);
        $this->assertStringContainsString('installed', $missingVendor->message);

        $this->writePackageState(null, '0.8.1', '0.8.1');
        $transitive = $this->planner()->plan('laravel/ai', '0.10');

        $this->assertSame('blocked', $transitive->status);
        $this->assertStringContainsString('direct root dependency', $transitive->message);
    }

    public function test_it_reports_unsupported_when_an_atomic_edge_is_missing(): void
    {
        $this->files->deleteDirectory($this->packagePath.'/resources/upgrades/laravel-ai/0.9-to-0.10');

        $plan = $this->planner()->plan('laravel/ai', '0.10');

        $this->assertSame('unsupported', $plan->status);
        $this->assertSame([], $plan->route);
    }

    public function test_it_reports_ambiguous_when_more_than_one_complete_route_exists(): void
    {
        $this->writeGuide('0.8', '0.10');
        $this->writeGeneratedSkills();

        $plan = $this->planner()->plan('laravel/ai', '0.10');

        $this->assertSame('ambiguous', $plan->status);
        $this->assertStringContainsString('More than one', $plan->message);
    }

    public function test_it_blocks_a_disabled_architecture_and_a_missing_generated_skill(): void
    {
        $this->writeConfig(enabled: false);
        $disabled = $this->planner()->plan('laravel/ai', '0.10');

        $this->assertSame('blocked', $disabled->status);
        $this->assertStringContainsString('not enabled', $disabled->message);
        $this->assertSame(['pending', 'pending'], array_map(fn ($step): string => $step->status, $disabled->route));

        $this->writeConfig(enabled: true);
        $this->files->delete($this->projectPath.'/.ai/skills/architecture-kit-upgrade-laravel-ai-0-8-to-0-9/SKILL.md');
        $missing = $this->planner()->plan('laravel/ai', '0.10');

        $this->assertSame('blocked', $missing->status);
        $this->assertStringContainsString('missing or outdated', $missing->message);
        $this->assertContains('run:architecture-kit:sync --no-interaction', $missing->next);

        $activePath = $this->projectPath.'/.ai/skills/architecture-kit-upgrade-laravel-ai-0-8-to-0-9/SKILL.md';
        $this->files->ensureDirectoryExists(dirname($activePath));
        $this->files->put($activePath, GeneratedResourceMarker::skill("# Different guide\n"));
        $outdated = $this->planner()->plan('laravel/ai', '0.10');

        $this->assertSame('blocked', $outdated->status);
        $this->assertStringContainsString('missing or outdated', $outdated->message);
    }

    private function planner(): UpgradePathPlanner
    {
        return new UpgradePathPlanner($this->files, $this->packagePath, $this->projectPath);
    }

    private function writePackageState(?string $constraint, string $locked, ?string $installed): void
    {
        $require = ['gracjankubicki/laravel-architecture-kit' => '^0.2'];

        if ($constraint !== null) {
            $require['laravel/ai'] = $constraint;
        }

        $this->files->put($this->projectPath.'/composer.json', json_encode([
            'require' => $require,
        ], JSON_THROW_ON_ERROR));
        $this->files->put($this->projectPath.'/composer.lock', json_encode([
            'packages' => [
                ['name' => 'laravel/ai', 'version' => $locked],
            ],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR));
        $this->files->ensureDirectoryExists($this->projectPath.'/vendor/composer');

        if ($installed === null) {
            $this->files->delete($this->projectPath.'/vendor/composer/installed.php');

            return;
        }

        $this->files->put(
            $this->projectPath.'/vendor/composer/installed.php',
            "<?php\nreturn ['versions' => ['laravel/ai' => ['pretty_version' => '{$installed}']]];\n",
        );
    }

    private function writeConfig(bool $enabled): void
    {
        $entry = $enabled
            ? '\\GracjanKubicki\\ArchitectureKit\\Architecture::LaravelAi,'
            : '\\GracjanKubicki\\ArchitectureKit\\Architecture::Actions,';

        $this->files->put($this->projectPath.'/config/architectures.php', <<<PHP
<?php

return [
    'enabled' => [
        {$entry}
    ],
];
PHP);
    }

    private function writeGuide(string $from, string $to): void
    {
        $name = 'architecture-kit-upgrade-laravel-ai-'.str_replace('.', '-', $from).'-to-'.str_replace('.', '-', $to);
        $path = $this->packagePath."/resources/upgrades/laravel-ai/{$from}-to-{$to}/SKILL.md";
        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, <<<MARKDOWN
---
name: {$name}
description: Upgrade fixture.
metadata:
  architecture: laravel-ai
  package: laravel/ai
  from: "{$from}"
  to: "{$to}"
---

# Upgrade
MARKDOWN);
    }

    private function writeGeneratedSkills(): void
    {
        foreach ($this->files->allFiles($this->packagePath.'/resources/upgrades') as $file) {
            if ($file->getFilename() !== 'SKILL.md') {
                continue;
            }

            $contents = $this->files->get($file->getPathname());
            preg_match('/^name: (?<name>[a-z0-9-]+)$/m', $contents, $matches);
            $path = $this->projectPath.'/.ai/skills/'.$matches['name'].'/SKILL.md';
            $this->files->ensureDirectoryExists(dirname($path));
            $this->files->put($path, GeneratedResourceMarker::skill($contents));
        }
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $snapshot = [];

        foreach ($this->files->allFiles($this->projectPath) as $file) {
            $path = str_replace($this->projectPath.'/', '', $file->getPathname());
            $snapshot[$path] = hash_file('sha256', $file->getPathname());
        }

        ksort($snapshot);

        return $snapshot;
    }
}
