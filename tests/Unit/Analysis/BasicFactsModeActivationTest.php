<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Analysis;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisSession;
use GracjanKubicki\ArchitectureKit\Analysis\BasicFactsOptions;
use GracjanKubicki\ArchitectureKit\Analysis\BasicFileFacts;
use GracjanKubicki\ArchitectureKit\Analysis\BasicSource;
use GracjanKubicki\ArchitectureKit\Analysis\MagoAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Query;
use GracjanKubicki\ArchitectureKit\Architecture\RoleClassifier;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\PortableAuditRule;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\FileGraphEntryCodec;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Audit\RuleRegistry;
use GracjanKubicki\ArchitectureKit\Catalog\BladePhpSource;
use GracjanKubicki\ArchitectureKit\Classification\ClassificationMappings;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Real executable required. Contract-only doubles do not satisfy these tests. */
final class BasicFactsModeActivationTest extends TestCase
{
    private function binary(): string
    {
        $binary = getenv('ARCHITECTURE_KIT_TEST_ENGINE');
        if (! is_string($binary) || $binary === '') {
            self::markTestSkipped('Set ARCHITECTURE_KIT_TEST_ENGINE to the native candidate.');
        }

        return $binary;
    }

    public function test_requested_basic_product_is_complete_and_does_not_transport_syntax(): void
    {
        $binary = $this->binary();
        $backend = new MagoAnalysisEngine($binary);
        $descriptor = $backend->descriptor();
        self::assertContains(BasicFileFacts::PRODUCT, $descriptor->capabilities);
        $source = new SourceInput('app/Example.php', '<?php class Example { function run() { new Other; } }', '8.3');
        $options = new BasicFactsOptions(true, true);
        $input = BasicSource::plain($source);
        $process = new Process([$binary], input: json_encode($input->request($descriptor, $options), JSON_THROW_ON_ERROR)."\n", timeout: 10);
        $process->mustRun();
        $raw = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(BasicFileFacts::PRODUCT, $raw['product']);
        self::assertSame(false, $raw['trace']['syntax_transport']);
        self::assertSame(true, $raw['trace']['ir_built']);
        self::assertArrayNotHasKey('syntax', $raw);
        self::assertArrayNotHasKey('facts', $raw);
        self::assertSame(['symbols', 'edges', 'test_invocations', 'impact', 'catalog', 'syntax_error', 'syntax_error_line'], array_keys($raw['entry']));
        self::assertSame('Example', BasicFileFacts::fromNative($raw, $input, $descriptor, $options)->entry->symbols[0]->name);
        // No product selector still means real portable syntax, not an empty tree.
        $syntax = $backend->analyze($source);
        self::assertNotEmpty($syntax->syntax);
        self::assertSame('statement.class', $syntax->syntax[0]->kind);
        self::assertSame(hash_file('sha256', $binary), $descriptor->implementation);
    }

    public function test_full_native_contributions_match_the_old_tree_path_of_the_same_binary(): void
    {
        $binary = $this->binary();
        $native = new AnalysisSession(new MagoAnalysisEngine($binary), '8.5');
        $reference = new AnalysisSession(new MagoAnalysisEngine($binary), '8.5');
        $cases = [];
        foreach (['basic-declarations.json', 'basic-impact.json', 'basic-catalog-parts.json', 'basic-tests.json'] as $name) {
            $fixtures = json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/Analysis/'.$name), true, flags: JSON_THROW_ON_ERROR);
            foreach ($fixtures['cases'] as $case) {
                $cases[$case['path'].hash('sha256', $case['source'])] = [$case['path'], $case['source']];
            }
        }
        $cases[] = ['app/Broken.php', "<?php\nclass Broken { function run("];
        $cases[] = ['resources/views/valid.blade.php', "α\r\n{{-- {{ new Hidden }} --}}\r\n{{ new \\App\\Example }}\r\n@php function renderExample() {} @endphp\n"];
        $cases[] = ['resources/views/broken.blade.php', "α\r\n@include('known')\r\n{{ new }}\r\n"];
        $cases[] = ['tests/IdentityParameterTest.php', '<?php class IdentityParameterTest { function test_route() { $this->get(route("route", [\'$identity\' => "value"])); } }'];
        foreach ([false, true] as $impact) {
            foreach ([false, true] as $catalog) {
                foreach ([false, true] as $occurrences) {
                    $builder = new ProjectGraphBuilder(impact: $impact, preserveOccurrences: $occurrences, catalog: $catalog);
                    $contexts = array_map(static fn ($case): FileContext => new FileContext($case[0], $case[1], analysis: $native), array_values($cases));
                    $actual = $builder->collectBasicMany($contexts);
                    foreach (array_values($cases) as $index => [$path, $contents]) {
                        $expected = $builder->collect(new FileContext($path, $contents, analysis: $reference));
                        self::assertSame(FileGraphEntryCodec::encode($expected), FileGraphEntryCodec::encode($actual[$index]), $path.' '.json_encode([$impact, $catalog, $occurrences]));
                    }
                    $referenceBuilder = new ProjectGraphBuilder;
                    $nativeBuilder = new ProjectGraphBuilder;
                    foreach ($actual as $index => $entry) {
                        [$path, $contents] = array_values($cases)[$index];
                        $nativeBuilder->addEntry($entry);
                        $referenceBuilder->addEntry($builder->collect(new FileContext($path, $contents, analysis: $reference)));
                    }
                    self::assertEquals($referenceBuilder->finish(), $nativeBuilder->finish());
                }
            }
        }
    }

    public function test_batch_boundaries_do_not_change_custom_classification_or_duplicate_identities(): void
    {
        $stages = [];
        $session = new AnalysisSession(new MagoAnalysisEngine($this->binary()), '8.3', stageObserver: static function (string $stage) use (&$stages): void {
            $stages[] = $stage;
        });
        $roles = new RoleClassifier(new ClassificationMappings(['roles' => [['path' => 'app/Custom', 'role' => 'domain']]]));
        $builder = new ProjectGraphBuilder(roles: $roles, impact: true, catalog: true);
        $files = [];
        for ($i = 0; $i < 65; $i++) {
            $files[] = new FileContext('app/Custom/Example'.$i.'.php', '<?php namespace App; class Example'.$i.' { function run() { new Other; } }', analysis: $session);
        }
        $full = $builder->collectBasicMany($files);
        self::assertSame(3, count(array_filter($stages, static fn ($stage): bool => $stage === 'basic.native.batch')));
        self::assertSame(65, count(array_filter($stages, static fn ($stage): bool => $stage === 'basic.native.extract')));
        $reverse = array_reverse($builder->collectBasicMany(array_reverse($files)));
        $split = [...$builder->collectBasicMany(array_slice($files, 0, 7)), ...$builder->collectBasicMany(array_slice($files, 7))];
        foreach ($full as $index => $entry) {
            self::assertSame('domain', $entry->symbols[0]->role);
            self::assertSame(FileGraphEntryCodec::encode($entry), FileGraphEntryCodec::encode($reverse[$index]));
            self::assertSame(FileGraphEntryCodec::encode($entry), FileGraphEntryCodec::encode($split[$index]));
        }
    }

    public function test_encoded_byte_boundary_splits_a_legal_raw_batch_without_losing_sources(): void
    {
        $stages = [];
        $session = new AnalysisSession(new MagoAnalysisEngine($this->binary()), '8.3', stageObserver: static function ($stage) use (&$stages): void {
            $stages[] = $stage;
        });
        $files = [];
        $source = '<?php class Example {} /*'.str_repeat("\t", 70000).'*/';
        for ($i = 0; $i < 32; $i++) {
            $files[] = new FileContext('app/Example'.$i.'.php', $source, analysis: $session);
        }
        self::assertLessThan(MagoAnalysisEngine::BASIC_BATCH_BYTES, strlen($source) * 32);
        self::assertGreaterThan(MagoAnalysisEngine::BASIC_BATCH_BYTES, strlen(json_encode($source, JSON_THROW_ON_ERROR)) * 32);
        $entries = (new ProjectGraphBuilder(catalog: true))->collectBasicMany($files);
        self::assertCount(32, $entries);
        self::assertSame(2, count(array_filter($stages, static fn ($stage): bool => $stage === 'basic.native.batch')));
        foreach ($entries as $i => $entry) {
            self::assertSame('app/Example'.$i.'.php', $entry->symbols[0]->path);
            self::assertNotNull($entry->catalog);
        }
    }

    public function test_legal_single_source_above_batch_bytes_keeps_the_32_mib_source_protocol(): void
    {
        $session = new AnalysisSession(new MagoAnalysisEngine($this->binary()), '8.3');
        $source = '<?php /*'.str_repeat("\t", 2200000).'*/';
        self::assertGreaterThan(MagoAnalysisEngine::BASIC_BATCH_BYTES, strlen(json_encode($source, JSON_THROW_ON_ERROR)));
        $entries = (new ProjectGraphBuilder)->collectBasicMany([
            new FileContext('routes/large.php', $source, analysis: $session),
            new FileContext('routes/small.php', '<?php new Example;', analysis: $session),
        ]);
        self::assertCount(2, $entries);
        self::assertSame('(file) routes/large.php', $entries[0]->symbols[0]->name);
        self::assertSame('Example', $entries[1]->edges[0]->to);
    }

    public function test_source_limit_and_target_diagnostics_keep_their_categories_and_session_can_continue(): void
    {
        $backend = new MagoAnalysisEngine($this->binary());
        $options = new BasicFactsOptions(true, true);
        $prefix = '<?php class Boundary {}';
        $at = new SourceInput('app/At.php', $prefix.str_repeat(' ', 100000 - strlen($prefix)), '8.3');
        $over = new SourceInput('app/Over.php', $at->contents.' ', '8.3');
        [$atFacts, $overFacts] = $backend->analyzeBasic([BasicSource::plain($at), BasicSource::plain($over)], $options);
        self::assertSame([], $atFacts->entry->catalog->diagnostics);
        self::assertSame('source_limit', $overFacts->entry->catalog->diagnostics[0]->code);
        self::assertSame([], $overFacts->entry->symbols);
        self::assertNull($overFacts->entry->syntaxError);
        $invalid = new SourceInput('app/Old.php', '<?php readonly class Example {}', '8.1');
        $valid = new SourceInput('app/Valid.php', '<?php class Example {}', '8.1');
        [$invalidFacts, $validFacts] = $backend->analyzeBasic([BasicSource::plain($invalid), BasicSource::plain($valid)], $options);
        self::assertSame('syntax_error', $invalidFacts->diagnostics[0]['category']);
        self::assertSame([], $invalidFacts->entry->symbols);
        self::assertSame([], $invalidFacts->entry->impact->calls);
        self::assertSame('Example', $validFacts->entry->symbols[0]->name);
    }

    public function test_more_than_32_sources_is_rejected_before_any_partial_acceptance(): void
    {
        $backend = new MagoAnalysisEngine($this->binary());
        $source = BasicSource::plain(new SourceInput('app/Example.php', '<?php class Example {}', '8.3'));
        try {
            $backend->analyzeBasic(array_fill(0, 33, $source), new BasicFactsOptions);
            self::fail('Oversized batch must be split before extraction.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('at most 32', $error->getMessage());
        }
        self::assertCount(32, $backend->analyzeBasic(array_fill(0, 32, $source), new BasicFactsOptions));
    }

    public function test_warm_partial_map_does_not_replace_the_tree_or_skip_a_portable_rule(): void
    {
        $session = new AnalysisSession(new MagoAnalysisEngine($this->binary()), '8.3');
        $parsed = 0;
        $file = new FileContext('app/Example.php', "<?php\nclass Example {}", static function () use (&$parsed): void {
            $parsed++;
        }, analysis: $session);
        $entries = (new ProjectGraphBuilder(catalog: true))->collectBasicMany([$file, new FileContext('app/Broken.php', '<?php class {', analysis: $session)]);
        self::assertNotNull($entries[1]->syntaxError);
        self::assertSame(1, $parsed);
        $rule = new class implements PortableAuditRule
        {
            public int $calls = 0;

            public function supports(string $path, array $enabled): bool
            {
                return true;
            }

            public function check(FileContext $file): array
            {
                $this->calls++;
                $findings = [];
                foreach (Query::ofKind($file->syntax() ?? [], 'statement.class') as $class) {
                    $findings[] = new AuditFinding('error', 'tree-read', $file->path, $class->getStartLine(), 'Portable rule actually read the class tree.');
                }

                return $findings;
            }
        };
        $registry = new RuleRegistry([$rule], $session->engine);
        $findings = $registry->customRules()[0]->check($file);
        self::assertSame(1, $rule->calls);
        self::assertCount(1, $findings);
        self::assertSame(2, $findings[0]->line);
        self::assertSame(2, $parsed);
    }

    public function test_prepared_blade_diagnostics_and_call_offsets_refer_to_original_utf8_bytes(): void
    {
        $backend = new MagoAnalysisEngine($this->binary());
        $session = new AnalysisSession($backend, '8.3');
        $source = "α\r\n{{ new Example }}\r\n";
        $input = (new BladePhpSource)->input(new FileContext('resources/views/valid.blade.php', $source, analysis: $session));
        $facts = $backend->analyzeBasic([$input], new BasicFactsOptions(true, true))[0];
        $calls = array_values(array_filter($facts->entry->catalog->relations, static fn ($relation): bool => $relation->kind === 'calls'));
        self::assertSame(strpos($source, 'new Example'), $calls[0]->metadata['offset']);
        self::assertSame(2, $calls[0]->line);
        self::assertSame($source, $facts->source->contents);
        $invalid = "α\r\n{{ new }}\r\n";
        $input = (new BladePhpSource)->input(new FileContext('resources/views/invalid.blade.php', $invalid, analysis: $session));
        $facts = $backend->analyzeBasic([$input], new BasicFactsOptions(true, true))[0];
        self::assertSame('syntax_error', $facts->diagnostics[0]['category']);
        self::assertSame(strpos($invalid, '}}'), $facts->diagnostics[0]['start']);
        self::assertSame([], $facts->entry->symbols);
    }

    public function test_real_native_flat_node_budget_accepts_the_boundary_and_rejects_one_more(): void
    {
        $backend = new MagoAnalysisEngine($this->binary());
        $at = BasicSource::plain(new SourceInput('routes/nodes.php', '<?php '.str_repeat(';', 100000), '8.3'));
        self::assertSame('(file) routes/nodes.php', $backend->analyzeBasic([$at], new BasicFactsOptions)[0]->entry->symbols[0]->name);
        $over = BasicSource::plain(new SourceInput('routes/nodes.php', $at->original->contents.';', '8.3'));
        $this->expectException(RuntimeException::class);
        $backend->analyzeBasic([$over], new BasicFactsOptions);
    }

    public function test_real_parser_recursion_boundary_keeps_syntax_error_and_does_not_expose_recovered_facts(): void
    {
        $binary = $this->binary();
        $backend = new MagoAnalysisEngine($binary);
        $descriptor = $backend->descriptor();
        $options = new BasicFactsOptions;
        $sources = [
            new SourceInput('routes/at-depth.php', '<?php '.str_repeat('{', 511).';'.str_repeat('}', 511), '8.3'),
            new SourceInput('routes/over-depth.php', '<?php '.str_repeat('{', 512).';'.str_repeat('}', 512), '8.3'),
            new SourceInput('app/After.php', '<?php class After {}', '8.3'),
        ];
        $requests = array_map(static fn ($source): array => BasicSource::plain($source)->request($descriptor, $options), $sources);
        $request = json_encode(['schema' => 1, 'product' => 'basic-file-facts-batch-v1', 'requests' => $requests], JSON_THROW_ON_ERROR)."\n";
        $process = new Process([$binary], input: $request, timeout: 30);
        $process->run();
        $directory = getenv('ARCHITECTURE_KIT_TEST_EVIDENCE_DIR');
        if (is_string($directory) && is_dir($directory)) {
            file_put_contents($directory.'/parser-depth.request.json', $request);
            file_put_contents($directory.'/parser-depth.response.json', $process->getOutput());
            file_put_contents($directory.'/parser-depth.stderr.log', $process->getErrorOutput());
            file_put_contents($directory.'/parser-depth.exit', $process->getExitCode()."\n");
            file_put_contents($directory.'/parser-depth.provenance.json', json_encode(['binary' => $binary, 'sha256' => hash_file('sha256', $binary), 'command' => [$binary],
                'php_target' => '8.3', 'case' => 'parser recursion limit; syntax_error, not unsupported_syntax', 'seed' => null,
                'source_hashes' => array_map(static fn ($source): string => $source->hash, $sources)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $response = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(0, $response['results'][0]['diagnostics']);
        self::assertSame('syntax_error', $response['results'][1]['diagnostics'][0]['category']);
        self::assertStringContainsString('Maximum recursion depth', $response['results'][1]['diagnostics'][0]['message']);
        self::assertSame(6 + 512, $response['results'][1]['diagnostics'][0]['start']);
        self::assertSame([], $response['results'][1]['entry']['symbols']);
        self::assertSame([], $response['results'][1]['entry']['edges']);
        self::assertSame([], $response['results'][2]['diagnostics']);
        self::assertSame('After', $response['results'][2]['entry']['symbols'][0][0]);
    }
}
