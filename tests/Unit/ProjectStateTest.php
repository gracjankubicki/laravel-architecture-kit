<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\ProjectState;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

class ProjectStateTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = sys_get_temp_dir().'/architecture-kit-state-'.uniqid('', true);
        (new Filesystem)->ensureDirectoryExists($this->tempPath.'/config');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['architecture_kit_state_config_reads']);
        (new Filesystem)->deleteDirectory($this->tempPath);

        parent::tearDown();
    }

    public function test_it_keeps_one_validated_config_and_catalog_snapshot_for_the_operation(): void
    {
        $files = new Filesystem;
        $files->put($this->tempPath.'/config/architectures.php', <<<'PHP'
<?php

use GracjanKubicki\ArchitectureKit\Architecture;

$GLOBALS['architecture_kit_state_config_reads'] = ($GLOBALS['architecture_kit_state_config_reads'] ?? 0) + 1;

return [
    'enabled' => [Architecture::Actions],
    'audit' => ['exclude' => ['app/Legacy/**']],
    'runtime' => ['driver' => 'local', 'php' => 'php'],
];
PHP);
        $GLOBALS['architecture_kit_state_config_reads'] = 0;

        $state = ProjectState::load($files, dirname(__DIR__, 2), $this->tempPath);
        $catalogArchitectures = $state->catalog->ordered($state->enabled);
        $resourceArchitectures = $state->resources->ordered($state->enabled);

        $this->assertSame(1, $GLOBALS['architecture_kit_state_config_reads']);
        $this->assertSame([Architecture::Actions], $state->config->read());
        $this->assertSame(['app/Legacy/**'], $state->config->auditExcludes());
        $this->assertSame('local', $state->config->runtime()['driver']);
        $this->assertSame(1, $GLOBALS['architecture_kit_state_config_reads']);
        $this->assertSame($catalogArchitectures, $resourceArchitectures);
        $this->assertSame($catalogArchitectures[0], $resourceArchitectures[0]);
    }

    public function test_it_resolves_fortify_and_inertia_profiles_independently(): void
    {
        $files = new Filesystem;
        $files->put($this->tempPath.'/config/architectures.php', <<<'PHP'
<?php

use GracjanKubicki\ArchitectureKit\Architecture;

return ['enabled' => [Architecture::Inertia, Architecture::Fortify]];
PHP);
        $files->put($this->tempPath.'/composer.json', json_encode([
            'require' => [
                'inertiajs/inertia-laravel' => '^3.0',
                'laravel/fortify' => '^1.0',
            ],
        ], JSON_THROW_ON_ERROR));
        $files->put($this->tempPath.'/composer.lock', json_encode([
            'packages' => [
                ['name' => 'inertiajs/inertia-laravel', 'version' => '3.1.0'],
                ['name' => 'laravel/fortify', 'version' => '1.31.0'],
            ],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR));
        $files->ensureDirectoryExists($this->tempPath.'/vendor/composer');
        $files->put($this->tempPath.'/vendor/composer/installed.php', <<<'PHP'
<?php
return ['versions' => [
    'inertiajs/inertia-laravel' => ['pretty_version' => '3.1.0'],
    'laravel/fortify' => ['pretty_version' => '1.31.0'],
]];
PHP);

        $state = ProjectState::load($files, dirname(__DIR__, 2), $this->tempPath);

        $this->assertSame('inertia@3', $state->inertia?->profile);
        $this->assertSame('fortify@1', $state->fortify?->profile);
        $state->assertCompatibility();
    }
}
