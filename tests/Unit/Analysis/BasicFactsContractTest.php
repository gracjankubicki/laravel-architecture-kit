<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Analysis;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisSession;
use GracjanKubicki\ArchitectureKit\Analysis\BasicFactsOptions;
use GracjanKubicki\ArchitectureKit\Analysis\BasicFileFacts;
use GracjanKubicki\ArchitectureKit\Analysis\EngineDescriptor;
use GracjanKubicki\ArchitectureKit\Analysis\PhpAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntry;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntryCodec;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Wire validation only. These tests do not claim that a native Basic extractor exists. */
final class BasicFactsContractTest extends TestCase
{
    private const SOURCE = <<<'PHP'
<?php
namespace App\Actions;
use App\Contracts\Gateway as Port;
trait Runs { public function run(): void {} }
interface Gateway { public function send(): void; }
final class Dispatch {
    use Runs { run as protected retry; }
    public function __construct(private Port $port) {}
    public function handle(): Port { $this->port->send(); return $this->port; }
}
function dispatch(Port $port): void { $port->send(); }
PHP;

    public function test_full_real_php_contribution_round_trips_without_dropping_any_product(): void
    {
        foreach ([
            ['app/Actions/Dispatch.php', self::SOURCE],
            ['tests/Feature/DispatchTest.php', "<?php\nnamespace Tests;\nclass DispatchTest { public function test_send() { \$this->get('/send'); \$this->get('/send'); new \\App\\Actions\\Dispatch(); } }\n"],
            ['routes/console.php', '<?php function boot() { new App\\Runner(); } boot();'],
            ['resources/views/report.blade.php', "α\r\n{{ new \\App\\Report() }}\r\n@php function renderReport() {} @endphp\n"],
            ['app/Broken.php', "<?php\nclass Broken { function run("],
            ['app/Empty.php', ''],
        ] as [$path, $contents]) {
            foreach ([false, true] as $impact) {
                foreach ([false, true] as $catalog) {
                    $options = new BasicFactsOptions($impact, $catalog, true);
                    [$source, $engine, $entry, $envelope] = $this->fixture($path, $contents, $options);
                    $facts = BasicFileFacts::decode(json_decode(json_encode($envelope, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR), $source, $engine, $options);
                    self::assertEquals($entry, $facts->entry, $path);
                    self::assertSame(FileGraphEntryCodec::encode($entry), FileGraphEntryCodec::encode($facts->entry), $path);
                    self::assertSame($envelope['diagnostics'], $facts->diagnostics);
                    $reference = new ProjectGraphBuilder;
                    $candidate = new ProjectGraphBuilder;
                    $reference->addEntry($entry);
                    $candidate->addEntry($facts->entry);
                    self::assertEquals($reference->finish(), $candidate->finish(), $path);
                }
            }
        }
    }

    public function test_missing_required_fields_never_become_empty_success(): void
    {
        $options = new BasicFactsOptions(true, true);
        [$source, $engine, , $envelope] = $this->fixture('app/Actions/Dispatch.php', self::SOURCE, $options);
        foreach (array_keys($envelope) as $key) {
            $mutation = $envelope;
            unset($mutation[$key]);
            $this->rejects($mutation, $source, $engine, $options);
        }
        foreach (array_keys($envelope['entry']) as $key) {
            $mutation = $envelope;
            unset($mutation['entry'][$key]);
            $this->rejects($mutation, $source, $engine, $options);
        }
    }

    public function test_identity_settings_and_positions_are_bound_to_the_expected_snapshot(): void
    {
        $options = new BasicFactsOptions(true, true);
        [$source, $engine, , $envelope] = $this->fixture('app/Actions/Dispatch.php', self::SOURCE, $options);
        foreach (['schema' => 2, 'product' => 'syntax-facts', 'extractor' => 2, 'path' => 'app/Other.php',
            'source_hash' => hash('sha256', 'other'), 'php_version' => '8.4', 'engine_fingerprint' => hash('sha256', 'other'),
            'configuration_fingerprint' => (new BasicFactsOptions)->fingerprint(), 'mapping_fingerprint' => 'other'] as $key => $value) {
            $mutation = $envelope;
            $mutation[$key] = $value;
            $this->rejects($mutation, $source, $engine, $options);
        }
        $mutation = $envelope;
        $mutation['entry']['symbols'][0][1] = $source->lineAt(strlen($source->contents)) + 1;
        $this->rejects($mutation, $source, $engine, $options);
        $mutation = $envelope;
        $mutation['entry']['catalog']['elements'][1]['offset'] = strlen($source->contents) + 1;
        $this->rejects($mutation, $source, $engine, $options);
        $mutation = $envelope;
        $mutation['entry']['catalog']['descriptor_receivers'] = [str_repeat('0', 64) => '@return:invalid'];
        $this->rejects($mutation, $source, $engine, $options);
        $mutation = $envelope;
        foreach ($mutation['entry']['catalog']['elements'] as &$element) {
            if (isset($element['metadata']['return_sites'])) {
                $element['metadata']['return_sites']['offsets'] = [strlen($source->contents) + 1];
                break;
            }
        }
        unset($element);
        $this->rejects($mutation, $source, $engine, $options);
        $mutation = $envelope;
        $mutation['entry']['catalog']['relations'][0]['from'] = 'element:missing';
        $this->rejects($mutation, $source, $engine, $options);
    }

    public function test_recovered_facts_and_mismatched_diagnostics_are_rejected(): void
    {
        $options = new BasicFactsOptions(true, true);
        [$source, $engine, , $envelope] = $this->fixture('app/Broken.php', "<?php\nclass Broken {", $options);
        self::assertNotEmpty($envelope['diagnostics']);
        $mutation = $envelope;
        $mutation['entry']['symbols'] = [['Recovered', 1, '', 'class', 'unknown', false]];
        $this->rejects($mutation, $source, $engine, $options);
        $mutation = $envelope;
        $mutation['diagnostics'] = [];
        $this->rejects($mutation, $source, $engine, $options);
        $mutation = $envelope;
        $mutation['entry']['syntax_error'] = 'Other error';
        $this->rejects($mutation, $source, $engine, $options);
    }

    public function test_mapping_and_configuration_versions_change_the_fingerprint(): void
    {
        $options = new BasicFactsOptions(true, true);
        [$source, $engine, , $envelope] = $this->fixture('app/Actions/Dispatch.php', self::SOURCE, $options);
        $original = BasicFileFacts::decode($envelope, $source, $engine, $options);
        $envelope['mapping_fingerprint'] = hash('sha256', 'blade-original-and-prepared-source-v2');
        $mapped = BasicFileFacts::decode($envelope, $source, $engine, $options, $envelope['mapping_fingerprint']);
        self::assertNotSame($original->fingerprint(), $mapped->fingerprint());
        self::assertNotSame($options->fingerprint(), (new BasicFactsOptions(true, true, true))->fingerprint());
        self::assertNotSame((new BasicFactsOptions)->fingerprint(), (new BasicFactsOptions(true))->fingerprint());
    }

    public function test_old_capability_is_not_accepted_as_a_full_map_product(): void
    {
        $options = new BasicFactsOptions;
        [$source, , , $envelope] = $this->fixture('app/Actions/Dispatch.php', self::SOURCE, $options);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('basic-file-facts-v1');
        BasicFileFacts::decode($envelope, $source, (new PhpAnalysisEngine)->descriptor(), $options);
    }

    /** @return array{SourceInput, EngineDescriptor, FileGraphEntry, array<string, mixed>} */
    private function fixture(string $path, string $contents, BasicFactsOptions $options): array
    {
        $backend = new PhpAnalysisEngine;
        $session = new AnalysisSession($backend, '8.3');
        $source = new SourceInput($path, $contents, '8.3');
        $file = new FileContext($path, $contents, analysis: $session);
        $entry = (new ProjectGraphBuilder(impact: $options->impact, preserveOccurrences: $options->preserveOccurrences, catalog: $options->catalog))->collect($file);
        $descriptor = $backend->descriptor();
        // The fixture advertises a contract under test, never native activation.
        $engine = new EngineDescriptor($descriptor->engine, $descriptor->version, [...$descriptor->capabilities, BasicFileFacts::PRODUCT], implementation: $descriptor->implementation);
        $diagnostics = $entry->syntaxError === null ? [] : $backend->analyze($source)->diagnostics;
        $envelope = ['schema' => EngineDescriptor::SCHEMA, 'product' => BasicFileFacts::PRODUCT, 'extractor' => BasicFileFacts::EXTRACTOR,
            'path' => $source->path, 'source_hash' => $source->hash, 'php_version' => $source->phpVersion,
            'engine_fingerprint' => $engine->fingerprint(), 'configuration_fingerprint' => $options->fingerprint(), 'mapping_fingerprint' => '',
            'entry' => FileGraphEntryCodec::encode($entry), 'diagnostics' => $diagnostics];

        return [$source, $engine, $entry, $envelope];
    }

    private function rejects(array $envelope, SourceInput $source, EngineDescriptor $engine, BasicFactsOptions $options): void
    {
        try {
            BasicFileFacts::decode($envelope, $source, $engine, $options);
            self::fail('Corrupt contribution was accepted.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }
}
