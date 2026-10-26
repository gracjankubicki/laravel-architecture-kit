<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class GraphDiagnosticDeltaTest extends TestCase
{
    /** @return array<string, mixed> */
    private function runGeneration(string $action, string $name): array
    {
        $path = $this->tempPath.'/'.$name.'.json';
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=1G', dirname(__DIR__).'/Fixtures/Graph/generation-worker.php', $this->tempPath, $action, $path]);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

        return json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    }

    /** @param list<array<string, mixed>> $rows
     * @return array<string, int> */
    private function multiset(array $rows): array
    {
        $set = [];
        foreach ($rows as $row) {
            $key = json_encode($row, JSON_THROW_ON_ERROR);
            $set[$key] = ($set[$key] ?? 0) + 1;
        }
        ksort($set);

        return $set;
    }

    public function test_diagnostic_multisets_and_deltas_follow_independent_oracles_including_unknown_names(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/config/architectures.php', '<?php return ["audit" => ["cache" => true]];');
        $caller = '<?php namespace App; function run(Ghost $g, UnresolvedOther $n) { $g->perform(); $n->missing(); $dynamic->unresolvedControl(); }';
        $files->put($this->tempPath.'/app/Caller.php', $caller);
        $previous = $previousOracle = [];
        foreach (['unknown', 'known', 'ambiguous', 'known-again', 'unknown-again'] as $step) {
            match ($step) {
                'known' => $files->put($this->tempPath.'/app/Ghost.php', '<?php namespace App; class Ghost { function perform() {} }'),
                'ambiguous' => $files->put($this->tempPath.'/app/Copy.php', '<?php namespace App; class Ghost { function perform() {} }'),
                'known-again' => $files->delete($this->tempPath.'/app/Copy.php'),
                'unknown-again' => $files->delete($this->tempPath.'/app/Ghost.php'),
                default => null,
            };
            $actual = $this->runGeneration('build', $step);
            $oracle = $this->runGeneration('oracle', $step.'-oracle');
            $this->assertNotSame($actual['pid'], $oracle['pid']);
            $set = $this->multiset($actual['state']['diagnostics']);
            $oracleSet = $this->multiset($oracle['state']['diagnostics']);
            $this->assertSame($oracleSet, $set, $step);
            $this->assertSame(array_diff_assoc($oracleSet, $previousOracle), array_diff_assoc($set, $previous), 'added:'.$step);
            $this->assertSame(array_diff_assoc($previousOracle, $oracleSet), array_diff_assoc($previous, $set), 'removed:'.$step);
            $this->assertSame(hash('sha256', $caller), hash_file('sha256', $this->tempPath.'/app/Caller.php'));
            $this->assertContains('unresolved_call', array_column($actual['state']['diagnostics'], 'code'), json_encode($actual['state']['diagnostics'], JSON_THROW_ON_ERROR));
            $previous = $set;
            $previousOracle = $oracleSet;
        }
        $diagnostics = $actual['state']['diagnostics'];
        $mutated = $diagnostics;
        $mutated[0]['line'] = ($mutated[0]['line'] ?? 1) + 1;
        $mutated[0]['subject'] = 'different-subject';
        $this->assertSame(array_count_values(array_column($diagnostics, 'code')), array_count_values(array_column($mutated, 'code')));
        $this->assertNotSame($this->multiset($diagnostics), $this->multiset($mutated));
        $mutated = $diagnostics;
        $mutated[] = ['code' => 'source_limit', 'message' => 'Artificial shared limitation', 'line' => 1];
        $this->assertNotSame($this->multiset($oracle['state']['diagnostics']), $this->multiset($mutated));
    }
}
