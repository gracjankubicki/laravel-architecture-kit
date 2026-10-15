<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Analysis;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisDocument;
use GracjanKubicki\ArchitectureKit\Analysis\EngineDescriptor;
use GracjanKubicki\ArchitectureKit\Analysis\MagoAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\PhpAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AnalysisContractTest extends TestCase
{
    public function test_initializer_grammar_and_runtime_errors_are_not_confused(): void
    {
        $engines = [new PhpAnalysisEngine];
        $binary = getenv('ARCHITECTURE_KIT_TEST_ENGINE');
        if (is_string($binary) && $binary !== '') {
            $engines[] = new MagoAnalysisEngine($binary);
        }
        foreach ($engines as $engine) {
            foreach ([
                'class Example { protected $connection = unknown(); }',
                '#[Hidden(unknown())] class Example {}',
                '#[Hidden($dynamic)] class Example {}',
                '#[Hidden(...ARGUMENTS)] class Example {}',
            ] as $code) {
                $document = $engine->analyze(new SourceInput('lib/Invalid.php', '<?php '.$code, '8.3'));
                self::assertFalse($document->complete(), $engine->descriptor()->engine.': '.$code);
                self::assertSame('syntax_error', $document->diagnostics[0]['category']);
                self::assertSame([], $document->facts);
            }
            foreach ([
                'class Example { function run() { return static function() { $this->call(); }; } }',
                'class Example { function run() { return static fn () => $this->call(); } }',
                '#[Hidden(UNKNOWN_COLUMNS)] class Example { protected $connection = UNKNOWN_CONNECTION; }',
                '#[Hidden(new Selector())] class Example { function run($selector = new Selector()) {} }',
                'enum Queue: string { case MAIN = "main"; } class Example { public $queue = Queue::MAIN->value; function run(string $queue = Queue::MAIN->value) {} }',
                'trait Loads { function run() {} } class Example { use Loads { run as final locked; } }',
            ] as $code) {
                self::assertTrue($engine->analyze(new SourceInput('lib/Valid.php', '<?php '.$code, '8.3'))->complete(), $code);
            }
            $old = $engine->analyze(new SourceInput('lib/Old.php', '<?php trait Loads { function run() {} } class Example { use Loads { run as final locked; } }', '8.2'));
            self::assertFalse($old->complete());
            self::assertSame('syntax_error', $old->diagnostics[0]['category']);
        }
    }

    private const SOURCE = <<<'PHP'
<?php
namespace Billing;
final class Example {
    public function handle($gateway): void {
        helper();
        $gateway->charge();
        self::retry(...);
        new Receipt();
    }
}
PHP;

    public function test_php_adapter_returns_only_neutral_facts_with_exact_locations(): void
    {
        $source = new SourceInput('lib/Example.php', self::SOURCE, '8.3');
        $result = (new PhpAnalysisEngine)->analyze($source);
        self::assertTrue($result->complete());
        self::assertSame(['class', 'method', 'function-call', 'method-call', 'static-callable', 'new'], array_column($result->facts, 'kind'));
        self::assertSame(['Example', 'handle', 'helper', 'charge', 'retry', 'Receipt'], array_column($result->facts, 'name'));
        self::assertSame(array_fill(0, 6, strpos(self::SOURCE, 'class Example')), array_column($result->facts, 'owner'));
        foreach ($result->facts as $fact) {
            self::assertSame($fact['name'], substr(self::SOURCE, $fact['start'], $fact['end'] - $fact['start']));
        }
        $receiver = $result->facts[3]['receiver'];
        self::assertSame('$gateway', substr(self::SOURCE, $receiver[0], $receiver[1] - $receiver[0]));
        self::assertSame($source, $result->source);
    }

    public function test_source_is_never_executed_and_invalid_file_has_no_recovery_facts(): void
    {
        $engine = new PhpAnalysisEngine;
        self::assertTrue($engine->analyze(new SourceInput('lib/Exit.php', '<?php exit(123);', '8.3'))->complete());
        $invalid = $engine->analyze(new SourceInput('lib/Broken.php', '<?php class Broken { function run(', '8.3'));
        self::assertFalse($invalid->complete());
        self::assertSame([], $invalid->facts);
        self::assertSame('syntax_error', $invalid->diagnostics[0]['category']);
        // A bad file does not contaminate the next file or stop its analysis.
        self::assertTrue($engine->analyze(new SourceInput('lib/Good.php', self::SOURCE, '8.3'))->complete());
    }

    public function test_fingerprint_keeps_engine_version_schema_source_path_and_target_version_separate(): void
    {
        $php = new EngineDescriptor('php', '1', ['syntax-facts']);
        $mago = new EngineDescriptor('mago', '1', ['syntax-facts']);
        $input = new SourceInput('lib/One.php', '<?php', '8.3');
        $keys = [$input->fingerprint($php), $input->fingerprint($mago),
            $input->fingerprint(new EngineDescriptor('php', '2', ['syntax-facts'])),
            (new SourceInput('lib/Two.php', '<?php', '8.3'))->fingerprint($php),
            (new SourceInput('lib/One.php', '<?php ', '8.3'))->fingerprint($php),
            (new SourceInput('lib/One.php', '<?php', '8.4'))->fingerprint($php)];
        self::assertCount(count($keys), array_unique($keys));
    }

    public function test_a_capability_is_not_inferred_from_the_existence_of_a_parser(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support [application-audit]');
        (new PhpAnalysisEngine)->descriptor()->requireCapability('application-audit');
    }

    public function test_invalid_fact_offsets_are_rejected_before_consumers_see_them(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AnalysisDocument(new SourceInput('lib/One.php', '<?php', '8.3'), (new PhpAnalysisEngine)->descriptor(),
            [['kind' => 'class', 'name' => 'One', 'start' => 1, 'end' => 200, 'owner' => null]]);
    }

    public function test_missing_mago_binary_is_an_explicit_failure_without_php_fallback(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('explicitly select PHP');
        (new MagoAnalysisEngine('/nonexistent/architecture-kit-engine'))->analyze(new SourceInput('lib/One.php', self::SOURCE, '8.3'));
    }

    public function test_real_mago_and_php_adapters_satisfy_the_same_expected_contract(): void
    {
        $binary = getenv('ARCHITECTURE_KIT_TEST_ENGINE');
        if (! is_string($binary) || $binary === '') {
            self::markTestSkipped('Set ARCHITECTURE_KIT_TEST_ENGINE to the built engine binary.');
        }
        $source = new SourceInput('lib/Example.php', self::SOURCE, '8.3');
        $mago = new MagoAnalysisEngine($binary);
        $result = $mago->analyze($source);
        self::assertTrue($result->complete());
        self::assertSame(['class', 'method', 'function-call', 'method-call', 'static-callable', 'new'], array_column($result->facts, 'kind'));
        self::assertSame(['Example', 'handle', 'helper', 'charge', 'retry', 'Receipt'], array_column($result->facts, 'name'));
        self::assertSame((new PhpAnalysisEngine)->analyze($source)->facts, $result->facts);
        $bad = $mago->analyze(new SourceInput('lib/Broken.php', '<?php class Broken { function run(', '8.3'));
        self::assertFalse($bad->complete());
        self::assertSame([], $bad->facts);
        self::assertSame('syntax_error', $bad->diagnostics[0]['category']);
        self::assertTrue($mago->analyze($source)->complete());
    }
}
