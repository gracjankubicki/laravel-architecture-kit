<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\MissingTestLevel;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class MissingTestFactoryTest extends TestCase
{
    public function test_convention_and_generic_do_not_credit_unused_factory_or_seeder(): void
    {
        $this->fixtures(<<<'CODE'
use \Illuminate\Database\Eloquent\Factories\HasFactory;
CODE);
        $this->assertSame(['database/factories/OtherFactory.php', 'database/seeders/DatabaseSeeder.php'], array_column($this->audit()->findings, 'path'));
    }

    public function test_model_parent_source_budget_is_reported(): void
    {
        $this->fixtures('use \\Illuminate\\Database\\Eloquent\\Factories\\HasFactory;');
        $this->write('app/Models/User.php', '<?php namespace App\\Models; class User extends BaseModel {}');
        $this->write('app/Models/BaseModel.php', '<?php namespace App\\Models; class BaseModel extends \\Illuminate\\Database\\Eloquent\\Model {} /*'.str_repeat('x', 100_001).'*/');
        $result = $this->audit();
        $this->assertContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($result->findings, 'code'));
        $this->assertStringContainsString('Source or memory budget exceeded for App\\Models\\BaseModel', implode(' ', array_column($result->findings, 'message')));
    }

    public function test_model_inheritance_depth_budget_is_reported(): void
    {
        $this->fixtures('');
        $this->write('app/Models/User.php', '<?php namespace App\\Models; class User extends Base0 {}');
        for ($i = 0; $i < 14; $i++) {
            $this->write('app/Models/Base'.$i.'.php', '<?php namespace App\\Models; class Base'.$i.' extends Base'.($i + 1).' {}');
        }
        $result = $this->audit();
        $this->assertContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($result->findings, 'code'));
        $this->assertStringContainsString('Inheritance depth budget exceeded', implode(' ', array_column($result->findings, 'message')));
    }

    public function test_explicit_override_wins_over_convention(): void
    {
        $this->fixtures(<<<'CODE'
use \Illuminate\Database\Eloquent\Factories\HasFactory;
protected static function newFactory() { return \Database\Factories\OtherFactory::new(); }
CODE);
        $this->assertSame(['database/factories/UserFactory.php', 'database/seeders/DatabaseSeeder.php'], array_column($this->audit()->findings, 'path'));
    }

    public function test_property_and_attribute_are_supported(): void
    {
        $this->fixtures('use \\Illuminate\\Database\\Eloquent\\Factories\\HasFactory; protected static $factory = \\Database\\Factories\\OtherFactory::class;');
        $this->assertNotContains('database/factories/OtherFactory.php', array_column($this->audit()->findings, 'path'));
        $this->write('app/Models/User.php', '<?php namespace App\\Models; #[\\Illuminate\\Database\\Eloquent\\Attributes\\UseFactory(\\Database\\Factories\\OtherFactory::class)] class User extends \\Illuminate\\Database\\Eloquent\\Model { use \\Illuminate\\Database\\Eloquent\\Factories\\HasFactory; }');
        $this->assertNotContains('database/factories/OtherFactory.php', array_column($this->audit()->findings, 'path'));
    }

    public function test_dynamic_override_is_incomplete(): void
    {
        $this->fixtures('use \\Illuminate\\Database\\Eloquent\\Factories\\HasFactory; protected static function newFactory() { return resolveFactory(); }');
        $result = $this->audit();
        $this->assertContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($result->findings, 'code'));
        $this->assertContains('database/factories/UserFactory.php', array_column($result->findings, 'path'));
    }

    public function test_alias_generic_confirms_the_runtime_choice(): void
    {
        $this->fixtures('use \\Illuminate\\Database\\Eloquent\\Factories\\HasFactory;');
        $this->write('app/Models/User.php', <<<'CODE'
<?php
namespace App\Models;
use Database\Factories\UserFactory as UF;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class User extends \Illuminate\Database\Eloquent\Model {
    /** @use HasFactory<UF> */
    use HasFactory;
}
CODE);
        $this->assertNotContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($this->audit()->findings, 'code'));
        $this->assertNotContains('database/factories/UserFactory.php', array_column($this->audit()->findings, 'path'));
    }

    public function test_model_reference_without_factory_call_does_not_use_factory(): void
    {
        $this->fixtures('use \\Illuminate\\Database\\Eloquent\\Factories\\HasFactory;');
        $this->write('tests/Feature/UserTest.php', '<?php it("model", function () { expect(\\App\\Models\\User::class)->toBeString(); });');
        $this->assertContains('database/factories/UserFactory.php', array_column($this->audit()->findings, 'path'));
    }

    public function test_dynamic_global_naming_prevents_convention_credit(): void
    {
        $this->fixtures('use \\Illuminate\\Database\\Eloquent\\Factories\\HasFactory;');
        $this->write('app/Providers/AppServiceProvider.php', '<?php namespace App\\Providers; class AppServiceProvider { public function boot(): void { \\Illuminate\\Database\\Eloquent\\Factories\\Factory::guessFactoryNamesUsing(fn ($model) => custom($model)); } }');
        $findings = $this->audit()->findings;
        $this->assertContains('W_MISSING_TEST_ANALYSIS_INCOMPLETE', array_column($findings, 'code'));
        $this->assertContains('database/factories/UserFactory.php', array_column($findings, 'path'));
    }

    public function test_model_subnamespace_maps_to_the_same_factory_subnamespace(): void
    {
        $this->fixtures('use \\Illuminate\\Database\\Eloquent\\Factories\\HasFactory;');
        $this->write('app/Models/Admin/Account.php', '<?php namespace App\\Models\\Admin; class Account extends \\Illuminate\\Database\\Eloquent\\Model { use \\Illuminate\\Database\\Eloquent\\Factories\\HasFactory; }');
        $this->write('database/factories/Admin/AccountFactory.php', '<?php namespace Database\\Factories\\Admin; class AccountFactory extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { public function definition(): array { return []; } }');
        $this->write('tests/Feature/UserTest.php', '<?php it("account", function () { \\App\\Models\\Admin\\Account::factory(); });');
        $this->assertNotContains('database/factories/Admin/AccountFactory.php', array_column($this->audit()->findings, 'path'));
    }

    public function test_eloquent_model_without_has_factory_reports_unresolved_factory(): void
    {
        $this->fixtures('');
        $findings = $this->audit()->findings;
        $incomplete = array_values(array_filter($findings, fn ($finding) => $finding->code === 'W_MISSING_TEST_ANALYSIS_INCOMPLETE'));
        $this->assertCount(1, $incomplete);
        $this->assertSame('tests/Feature/UserTest.php', $incomplete[0]->path);
        $this->assertSame(1, $incomplete[0]->line);
        $this->assertStringContainsString('without HasFactory', $incomplete[0]->message);
        $this->assertContains('database/factories/UserFactory.php', array_column($findings, 'path'));
    }

    public function test_changed_model_keeps_diagnostic_from_unchanged_factory_test(): void
    {
        $this->fixtures('');
        foreach ([['git', 'init', '-q'], ['git', 'add', 'app', 'database', 'tests'], ['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '-qm', 'fixture']] as $command) {
            (new Process($command, $this->tempPath))->mustRun();
        }
        file_put_contents($this->tempPath.'/app/Models/User.php', "\n// changed model only", FILE_APPEND);
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run([], true, scope: new AuditScope(['app', 'database', 'tests']), missingTestLevel: MissingTestLevel::Warn);
        $this->assertCount(1, $result->findings);
        $this->assertSame('W_MISSING_TEST_ANALYSIS_INCOMPLETE', $result->findings[0]->code);
        $this->assertSame('tests/Feature/UserTest.php', $result->findings[0]->path);
    }

    private function fixtures(string $body): void
    {
        $this->write('app/Models/User.php', '<?php namespace App\\Models; class User extends \\Illuminate\\Database\\Eloquent\\Model { '.$body.' }');
        foreach (['UserFactory', 'OtherFactory'] as $class) {
            $this->write('database/factories/'.$class.'.php', '<?php namespace Database\\Factories; class '.$class.' extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory { public function definition(): array { return []; } }');
        }
        $this->write('database/seeders/DatabaseSeeder.php', '<?php namespace Database\\Seeders; class DatabaseSeeder { public function run(): void {} }');
        $this->write('tests/Feature/UserTest.php', '<?php it("user", function () { \\App\\Models\\User::factory()->create(); });');
    }

    private function audit(): ApplicationAuditResult
    {
        return (new ApplicationAudit(new Filesystem, $this->tempPath))->run([], false, scope: new AuditScope(['app', 'database', 'tests']), missingTestLevel: MissingTestLevel::Warn);
    }

    private function write(string $path, string $contents): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $contents);
    }
}
