<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Analysis;

use GracjanKubicki\ArchitectureKit\Analysis\AnalysisDocument;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\AnalysisSession;
use GracjanKubicki\ArchitectureKit\Analysis\BasicSource;
use GracjanKubicki\ArchitectureKit\Analysis\EngineDescriptor;
use GracjanKubicki\ArchitectureKit\Analysis\PhpAnalysisEngine;
use GracjanKubicki\ArchitectureKit\Analysis\SourceInput;
use GracjanKubicki\ArchitectureKit\Analysis\Syntax\Schema;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Catalog\BladePhpSource;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class BasicSourceContractTest extends TestCase
{
    public function test_native_ir_traversal_vocabulary_matches_the_shared_php_schema(): void
    {
        self::assertSame(Schema::FIELDS, json_decode(file_get_contents(dirname(__DIR__, 3).'/engine/src/basic_fields.json'), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_preparation_is_parser_free_and_fingerprints_both_byte_snapshots_and_mapping_version(): void
    {
        $parsed = [];
        $file = new FileContext('resources/views/example.blade.php', "α\r\n{{ new Example }}\r\n", static function ($path) use (&$parsed): void {
            $parsed[] = $path;
        }, analysis: new AnalysisSession(new PhpAnalysisEngine, '8.3'));
        $input = (new BladePhpSource)->input($file);
        self::assertSame([], $parsed);
        self::assertSame($file->contents, $input->original->contents);
        self::assertStringContainsString('<?php ', $input->prepared->contents);
        self::assertNotSame($input->original->hash, $input->prepared->hash);
        $versioned = new BasicSource($input->original, $input->prepared, $input->segments, BladePhpSource::MAPPING_VERSION.'-changed');
        self::assertNotSame($input->mappingFingerprint, $versioned->mappingFingerprint);
        $mutated = $input->segments;
        $mutated[0]['start']++;
        $this->expectException(InvalidArgumentException::class);
        new BasicSource($input->original, $input->prepared, $mutated, BladePhpSource::MAPPING_VERSION);
    }

    public function test_changed_prepared_bytes_cannot_claim_an_untransformed_snapshot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BasicSource(new SourceInput('example.php', '<?php', '8.3'), new SourceInput('example.php', '<?php ', '8.3'));
    }

    public function test_syntax_mapping_parses_prepared_php_once_without_parsing_the_original_template(): void
    {
        $backend = new class implements AnalysisEngine
        {
            public array $inputs = [];

            public function descriptor(): EngineDescriptor
            {
                return (new PhpAnalysisEngine)->descriptor();
            }

            public function analyze(SourceInput $source): AnalysisDocument
            {
                $this->inputs[] = $source->contents;

                return (new PhpAnalysisEngine)->analyze($source);
            }
        };
        $original = "α\r\n{{ new Example }}\r\n";
        $file = new FileContext('resources/views/example.blade.php', $original, analysis: new AnalysisSession($backend, '8.3'));
        $mapped = (new BladePhpSource)->prepare($file);
        self::assertCount(1, $backend->inputs);
        self::assertNotSame($original, $backend->inputs[0]);
        self::assertNotNull($mapped->syntax());
        self::assertSame($original, $mapped->contents);
        self::assertCount(1, $backend->inputs);
    }

    public function test_explicit_php_map_works_in_a_child_without_any_mago_binary(): void
    {
        $autoload = var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true);
        $code = 'require '.$autoload.'; '.<<<'PHP'
            $session = new GracjanKubicki\ArchitectureKit\Analysis\AnalysisSession(new GracjanKubicki\ArchitectureKit\Analysis\PhpAnalysisEngine, '8.3');
            $file = new GracjanKubicki\ArchitectureKit\Audit\FileContext('app/Example.php', '<?php class Example { function run() { new Other; } }', analysis: $session);
            $builder = new GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder(impact: true, catalog: true);
            $entry = $builder->collectBasicMany([$file])[0];
            echo json_encode([$session->engine->engine, $entry->symbols[0]->name, $entry->edges[0]->to, count($entry->catalog->elements)], JSON_THROW_ON_ERROR);
            PHP;
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G', '-r', $code], env: ['PATH' => '', 'ARCHITECTURE_KIT_TEST_ENGINE' => '/nonexistent/native-engine', 'ARCHITECTURE_KIT_SUITE_ENGINE' => 'php']);
        $process->mustRun();
        self::assertSame(['php', 'Example', 'Other', 3], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }
}
