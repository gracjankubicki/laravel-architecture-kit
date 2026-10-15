<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Standalone;

use GracjanKubicki\ArchitectureKit\Standalone\JsonResponseChunks;
use JsonException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class JsonResponseChunksTest extends TestCase
{
    public function test_chunks_match_sdk_json_for_unicode_escaping_and_object_shapes(): void
    {
        $message = ['jsonrpc' => '2.0', 'id' => 'pełny/raport', 'result' => [
            'content' => [['type' => 'text', 'text' => str_repeat('a', 65535)."ż/\"\n\\".str_repeat('ą', 70000)]],
            'structuredContent' => ['empty' => (object) [], 'list' => [], 'map' => [2 => true, 4 => null], 'float' => 1.5],
        ]];
        $chunks = iterator_to_array(JsonResponseChunks::encode($message), false);
        self::assertSame(json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), implode('', $chunks));
        self::assertLessThan(400000, max(array_map(strlen(...), $chunks)));
    }

    public function test_invalid_nested_data_is_rejected_before_the_first_output_chunk(): void
    {
        $output = '';
        try {
            foreach (JsonResponseChunks::encode(['valid' => 'first', 'invalid' => "\xFF"]) as $chunk) {
                $output .= $chunk;
            }
            self::fail('Invalid UTF-8 was accepted.');
        } catch (JsonException) {
            self::assertSame('', $output);
        }
    }

    public function test_report_sized_strings_are_serialized_with_bounded_memory(): void
    {
        $program = <<<'PHP'
require $_SERVER['argv'][1];
$message = ['result' => ['content' => [['type' => 'text', 'text' => str_repeat('"/', 20000000)]]]];
$hash = hash_init('sha256');
$bytes = 0;
foreach (GracjanKubicki\ArchitectureKit\Standalone\JsonResponseChunks::encode($message) as $chunk) {
    hash_update($hash, $chunk);
    $bytes += strlen($chunk);
}
echo json_encode(['bytes' => $bytes, 'hash' => hash_final($hash), 'peak' => memory_get_peak_usage(true)]);
PHP;
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $program, '--', dirname(__DIR__, 3).'/vendor/autoload.php'], timeout: 30);
        self::assertSame(0, $process->run(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertGreaterThan(79000000, $result['bytes']);
        self::assertLessThan(80 * 1024 * 1024, $result['peak']);
        self::assertSame(64, strlen($result['hash']));
    }
}
