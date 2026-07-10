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
}
