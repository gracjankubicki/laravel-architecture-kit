<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Analysis;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisDocument;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisSession;
use GracjanKubicki\ArchitectureKit\Analysis\EngineDescriptor;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AnalysisSessionTest extends TestCase
{
    public function test_wrong_snapshot_or_engine_poisons_the_session_without_retrying(): void
    {
        foreach (['path', 'contents', 'version', 'engine'] as $change) {
            $backend = new class($change) implements AnalysisEngine
            {
                public int $calls = 0;

                public function __construct(private readonly string $change) {}

                public function descriptor(): EngineDescriptor
                {
                    return new EngineDescriptor('php', '1', ['syntax-tree-v1']);
                }

                public function analyze(SourceInput $source): AnalysisDocument
                {
                    $this->calls++;

                    return new AnalysisDocument(new SourceInput(
                        $this->change === 'path' ? 'other.php' : $source->path,
                        $this->change === 'contents' ? '<?php // changed' : $source->contents,
                        $this->change === 'version' ? '8.4' : $source->phpVersion,
                    ), $this->change === 'engine' ? new EngineDescriptor('php', '2', ['syntax-tree-v1']) : $this->descriptor(), []);
                }
            };
            $session = new AnalysisSession($backend, '8.3');
            foreach (['belongs to another', 'session failed'] as $message) {
                try {
                    $session->analyze('one.php', '<?php');
                    self::fail('An untrusted result cannot be accepted.');
                } catch (RuntimeException $error) {
                    self::assertStringContainsString($message, $error->getMessage());
                }
            }
            self::assertSame(1, $backend->calls);
            try {
                $session->withPhpVersion('8.4');
                self::fail('A failed session cannot be revived by changing the target.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('no longer valid', $error->getMessage());
            }
        }
    }

    public function test_changed_implementation_is_rejected_before_another_source_is_read(): void
    {
        $backend = new class implements AnalysisEngine
        {
            public string $version = '1';

            public int $calls = 0;

            public function descriptor(): EngineDescriptor
            {
                return new EngineDescriptor('php', $this->version, ['syntax-tree-v1']);
            }

            public function analyze(SourceInput $source): AnalysisDocument
            {
                $this->calls++;

                return new AnalysisDocument($source, $this->descriptor(), []);
            }
        };
        $session = new AnalysisSession($backend, '8.3');
        self::assertTrue($session->analyze('one.php', '<?php')->complete());
        $backend->version = '2';
        try {
            $session->analyze('two.php', '<?php');
            self::fail('Cannot mix engine versions.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('changed during the session', $error->getMessage());
        }
        self::assertSame(1, $backend->calls);
    }
}
