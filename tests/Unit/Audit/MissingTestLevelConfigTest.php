<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit;

use GracjanKubicki\ArchitectureKit\Audit\MissingTestLevel;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MissingTestLevelConfigTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = sys_get_temp_dir().'/architecture-kit-scope-'.uniqid('', true);
        (new Filesystem)->ensureDirectoryExists($this->tempPath.'/config');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->tempPath);

        parent::tearDown();
    }

    public function test_a_config_without_the_key_leaves_the_rule_off(): void
    {
        // Every installation that predates this feature lands here, and must keep the
        // audit result it had before the upgrade.
        $this->writeConfig(<<<'PHP'
<?php

use GracjanKubicki\ArchitectureKit\Architecture;

return [
    'enabled' => [
        Architecture::Actions,
    ],
];
PHP);

        $this->assertSame(MissingTestLevel::Off, $this->config()->missingTestLevel());
        $this->assertSame(['app'], $this->config()->auditScope()->directories);
    }

    public function test_enabling_the_rule_brings_the_test_directory_into_scope(): void
    {
        // Without this the rule would read a graph with no test files in it and report
        // every class as untested, including well covered ones.
        $this->writeConfig(<<<'PHP'
<?php

use GracjanKubicki\ArchitectureKit\Architecture;

return [
    'enabled' => [
        Architecture::Actions,
    ],
    'audit' => [
        'missing_test' => 'warn',
    ],
];
PHP);

        $scope = $this->config()->auditScope();

        $this->assertSame(MissingTestLevel::Warn, $this->config()->missingTestLevel());
        $this->assertTrue($scope->includesTests());
    }

    public function test_extra_paths_are_added_without_losing_the_application_directory(): void
    {
        $this->writeConfig(<<<'PHP'
<?php

use GracjanKubicki\ArchitectureKit\Architecture;

return [
    'enabled' => [
        Architecture::Actions,
    ],
    'audit' => [
        'paths' => ['routes'],
    ],
];
PHP);

        $scope = $this->config()->auditScope();

        $this->assertSame(['app', 'routes'], $scope->directories);
        $this->assertFalse($scope->includesTests());
    }

    public function test_an_unknown_level_is_rejected_with_the_allowed_values(): void
    {
        $this->writeConfig(<<<'PHP'
<?php

use GracjanKubicki\ArchitectureKit\Architecture;

return [
    'enabled' => [
        Architecture::Actions,
    ],
    'audit' => [
        'missing_test' => 'yes',
    ],
];
PHP);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/off, warn, error/');

        $this->config()->missingTestLevel();
    }

    public function test_the_enum_can_be_written_directly_in_the_config(): void
    {
        $this->writeConfig(<<<'PHP'
<?php

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\MissingTestLevel;

return [
    'enabled' => [
        Architecture::Actions,
    ],
    'audit' => [
        'missing_test' => MissingTestLevel::Error,
    ],
];
PHP);

        $this->assertSame(MissingTestLevel::Error, $this->config()->missingTestLevel());
        $this->assertTrue($this->config()->auditScope()->includesTests());
    }

    private function config(): ArchitectureConfig
    {
        return new ArchitectureConfig($this->tempPath.'/config/architectures.php', new Filesystem);
    }

    private function writeConfig(string $contents): void
    {
        (new Filesystem)->put($this->tempPath.'/config/architectures.php', $contents);
    }
}
