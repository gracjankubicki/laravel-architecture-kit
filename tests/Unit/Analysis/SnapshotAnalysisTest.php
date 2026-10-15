<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Analysis;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisRuntime;
use GracjanKubicki\ArchitectureKit\Analysis\SnapshotAnalysis;
use GracjanKubicki\ArchitectureKit\Revision\SourceSnapshot;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SnapshotAnalysisTest extends TestCase
{
    public function test_historical_target_uses_its_own_override_and_restores_current_session(): void
    {
        $current = AnalysisRuntime::session();
        $files = ['composer.json' => '{"require":{"php":"^8.1"}}',
            'config/architectures.php' => '<?php return ["analysis" => ["php_version" => "8.3"]];'];
        $source = new SourceSnapshot('git', 'old', 'hash', $files, [], array_keys($files));
        SnapshotAnalysis::read($source, static function () use ($current): void {
            self::assertSame('8.3', AnalysisRuntime::session()->phpVersion);
            self::assertSame($current->engine->fingerprint(), AnalysisRuntime::session()->engine->fingerprint());
            self::assertTrue(AnalysisRuntime::session()->analyze('lib/A.php', '<?php class A {}')->complete());
        });
        self::assertSame($current, AnalysisRuntime::session());
        unset($files['config/architectures.php']);
        $old = SnapshotAnalysis::session(new SourceSnapshot('git', 'older', 'hash', $files, [], array_keys($files)));
        self::assertSame('8.1.0', $old->phpVersion);
        self::assertFalse($old->analyze('lib/A.php', '<?php readonly class A {}')->complete());
    }

    public function test_unresolved_historical_configuration_never_hides_a_possible_php_override(): void
    {
        $source = new SourceSnapshot('git', 'old', 'hash', [
            'composer.json' => '{"require":{"php":"^8.1"}}',
            'config/architectures.php' => '<?php return runtime_settings();',
        ], [], ['composer.json', 'config/architectures.php']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot establish the project PHP target');
        SnapshotAnalysis::session($source);
    }
}
