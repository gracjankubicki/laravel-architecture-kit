<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Audit;

use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;

final class ApplicationAuditMemoryTest extends TestCase
{
    public function test_it_reports_an_injected_memory_budget_exceeded_during_the_file_pass(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/User.php', '<?php final class User {}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('after processing 1 file');

        $memoryChecks = 0;

        // Reads per file: budget pre-check, syntax-tree headroom, budget post-check.
        // Only the post-check read exceeds the budget here.
        (new ApplicationAudit(
            files: $files,
            basePath: $this->tempPath,
            memoryLimitBytes: 1_000_000,
            memoryBudgetRatio: 1.0,
            memoryUsage: function () use (&$memoryChecks): int {
                return $memoryChecks++ < 2 ? 0 : 1_000_001;
            },
        ))->run([], changedOnly: false);
    }

    public function test_it_checks_the_memory_budget_before_parsing_the_next_file(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/User.php', '<?php function (');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('after processing 0 files');

        (new ApplicationAudit(
            files: $files,
            basePath: $this->tempPath,
            memoryLimitBytes: 100,
            memoryBudgetRatio: 1.0,
            memoryUsage: static fn (): int => 101,
        ))->run([], changedOnly: false);
    }

    public function test_it_stops_before_parsing_a_file_whose_syntax_tree_cannot_fit_the_remaining_budget(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Huge.php', '<?php final class Huge { '.str_repeat('public int $n = 1; ', 500).'}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Audit stopped before parsing [app/Huge.php]');

        // Usage stays inside the budget, so only the estimated syntax-tree cost can stop the audit.
        (new ApplicationAudit(
            files: $files,
            basePath: $this->tempPath,
            memoryLimitBytes: 20_000,
            memoryBudgetRatio: 1.0,
            memoryUsage: static fn (): int => 0,
        ))->run([], changedOnly: false);
    }

    public function test_it_re_reads_the_headroom_after_tokenizing_before_it_trusts_the_estimate(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        // 7625 bytes and 4410 tokens: too large for the byte ceiling fast path, cheap
        // enough for the tokenizer guard, and an estimate of 3.18 MB.
        $files->put($this->tempPath.'/app/Big.php', '<?php final class Big { '.str_repeat('public int $n = 1; ', 400).'}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Audit stopped before parsing [app/Big.php]');

        $reads = 0;

        // Reads: budget pre-check, headroom before tokenizing, headroom after it.
        // The estimate fits the 4 MB headroom seen before tokenizing and does not fit
        // the 1 MB left afterwards, so this only passes when the guard measures again.
        (new ApplicationAudit(
            files: $files,
            basePath: $this->tempPath,
            memoryLimitBytes: 10_000_000,
            memoryBudgetRatio: 1.0,
            memoryUsage: function () use (&$reads): int {
                return match ($reads++) {
                    0 => 1_000_000,
                    1 => 6_000_000,
                    default => 9_000_000,
                };
            },
        ))->run([], changedOnly: false);
    }

    public function test_it_audits_normally_when_the_estimated_syntax_tree_fits_the_budget(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/User.php', '<?php final class User {}');

        $result = (new ApplicationAudit(
            files: $files,
            basePath: $this->tempPath,
            memoryLimitBytes: 64 * 1024 * 1024,
            memoryBudgetRatio: 1.0,
            memoryUsage: static fn (): int => 0,
        ))->run([], changedOnly: false);

        $this->assertSame('all application files', $result->scope);
        $this->assertSame([], $result->findings);
    }

    /**
     * The estimate must never fall below the real cost, otherwise the audit dies with
     * PHP's own out-of-memory error instead of the readable failure AC-02 requires.
     * These samples are the measured extremes of node density per source byte.
     *
     * @return array<string, array{0: string}>
     */
    public static function densitySamples(): array
    {
        return [
            'dense array of short literals' => ['<?php $a = ['.str_repeat('1,', 20000).'];'],
            'chained index access' => ['<?php $x = $a'.str_repeat('[1]', 4000).';'],
            'many short assignments' => ['<?php '.implode(' ', array_map(static fn (int $i): string => "\$a{$i} = {$i};", range(1, 8000)))],
            'one very long string' => ['<?php $s = "'.str_repeat('x', 200000).'";'],
            'comments only' => ['<?php '.str_repeat("// a filler comment line\n", 6000)],
            'ordinary class' => [file_get_contents(__DIR__.'/../../../src/Output/AgentOutput.php') ?: '<?php'],
        ];
    }

    #[DataProvider('densitySamples')]
    public function test_the_estimate_never_falls_below_the_real_syntax_tree_cost(string $source): void
    {
        gc_collect_cycles();
        $before = memory_get_usage();
        $file = new FileContext('app/Sample.php', $source);
        $this->assertNotNull($file->ast());
        $realCost = memory_get_usage() - $before;
        $file->releaseAst();

        $this->assertGreaterThan(0, $realCost, 'The measurement observed no syntax-tree allocation.');
        $this->assertGreaterThanOrEqual(
            $realCost,
            $this->estimate($source),
            sprintf('Estimated %d bytes for a tree that really needed %d bytes.', $this->estimate($source), $realCost),
        );
    }

    public function test_the_byte_ceiling_is_not_below_the_measured_worst_case_density(): void
    {
        $source = '<?php $a = ['.str_repeat('1,', 20000).'];';

        gc_collect_cycles();
        $before = memory_get_usage();
        $file = new FileContext('app/Dense.php', $source);
        $this->assertNotNull($file->ast());
        $realCost = memory_get_usage() - $before;
        $file->releaseAst();

        // The ceiling drives the fast path: a file that fits at this ratio is never
        // inspected further, so it must bound the densest tree we can produce.
        $this->assertGreaterThanOrEqual(
            $realCost / strlen($source),
            (float) $this->constant('AST_MEMORY_PER_SOURCE_BYTE_CEILING'),
        );
    }

    private function estimate(string $source): int
    {
        $tokens = @token_get_all($source);

        return count($tokens) * $this->constant('AST_MEMORY_PER_TOKEN')
            + strlen($source) * $this->constant('AST_MEMORY_PER_SOURCE_BYTE');
    }

    private function constant(string $name): int
    {
        $value = (new ReflectionClass(ApplicationAudit::class))->getConstant($name);

        $this->assertIsInt($value);

        return $value;
    }
}
