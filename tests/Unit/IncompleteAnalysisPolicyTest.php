<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use GracjanKubicki\ArchitectureKit\Audit\AnalysisNotice;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Doctor\ArchitectureDoctorResult;
use GracjanKubicki\ArchitectureKit\Guard\ArchitectureGuardResult;
use GracjanKubicki\ArchitectureKit\Output\AgentOutput;
use GracjanKubicki\ArchitectureKit\Standalone\StandaloneAudit;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IncompleteAnalysisPolicyTest extends TestCase
{
    #[DataProvider('settings')]
    public function test_policy_default_explicit_values_and_invalid_values(array $audit, ?bool $block): void
    {
        $directory = sys_get_temp_dir().'/kit-policy-'.bin2hex(random_bytes(8));
        mkdir($directory.'/config', 0700, true);
        file_put_contents($directory.'/config/architectures.php', '<?php return '.var_export(['audit' => $audit], true).';');
        try {
            if ($block === null) {
                $this->expectException(InvalidArgumentException::class);
                $this->expectExceptionMessage('audit.incomplete_analysis must be one of: warn, block.');
            }
            self::assertSame($block, (new ArchitectureConfig($directory.'/config/architectures.php'))->blocksIncompleteAnalysis());
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public static function settings(): iterable
    {
        yield 'existing project without setting' => [[], false];
        yield 'warn' => [['incomplete_analysis' => 'warn'], false];
        yield 'block' => [['incomplete_analysis' => 'block'], true];
        foreach ([null, false, true, 1, [], 'off', 'BLOCK'] as $index => $value) {
            yield 'invalid '.$index => [['incomplete_analysis' => $value], null];
        }
    }

    #[DataProvider('analysisCodes')]
    public function test_analysis_only_warnings_remain_visible_but_follow_policy_even_in_strict(string $code): void
    {
        $audit = new ApplicationAuditResult('app', [$this->finding('warn', $code)]);
        self::assertTrue($audit->incomplete());
        self::assertSame(1, $audit->warnings());
        self::assertSame(0, $audit->architectureWarnings());
        foreach ([false, true] as $strict) {
            foreach ([false, true] as $block) {
                $guard = $this->guard($audit, $strict, $block);
                self::assertSame(! $block, $guard->ok());
                self::assertSame(! $block, $guard->toArray()['audit']['ok']);
                self::assertSame('incomplete', $guard->toArray()['audit']['analysis']['status']);
                $agent = (new AgentOutput)->guard($guard);
                self::assertSame($block ? 'fail' : 'ok', $agent['audit']);
                self::assertSame($code, $agent['find'][0]['m']);
                self::assertSame('incomplete', $agent['analysis']['status']);
            }
        }
    }

    public static function analysisCodes(): iterable
    {
        foreach (['W_FORTIFY_ANALYSIS_INCOMPLETE', 'W_LARAVEL_AI_ANALYSIS_INCOMPLETE', 'W_INERTIA_REQUEST_PROPS_ANALYSIS_INCOMPLETE', 'W_THIN_CONTROLLER_READ_ANALYSIS_INCOMPLETE', 'W_MISSING_TEST_ANALYSIS_INCOMPLETE'] as $code) {
            yield $code => [$code];
        }
    }

    public function test_notices_and_status_follow_policy_without_hiding_ordinary_warnings_or_errors(): void
    {
        foreach ([new ApplicationAuditResult('app', [], analysisStatus: 'incomplete'), new ApplicationAuditResult('app', [], notices: [$this->notice()])] as $audit) {
            self::assertTrue($this->guard($audit, true, false)->ok());
            self::assertFalse($this->guard($audit, false, true)->ok());
        }
        foreach (['W_CUSTOM_ANALYSIS_INCOMPLETE', 'W_MISSING_TEST', 'W_THIN_CONTROLLER_READ_SERVICE'] as $code) {
            $audit = new ApplicationAuditResult('app', [$this->finding('warn', $code)]);
            self::assertFalse($audit->incomplete());
            self::assertTrue($this->guard($audit, false, false)->ok());
            self::assertFalse($this->guard($audit, true, false)->ok());
        }
        $error = new ApplicationAuditResult('app', [$this->finding('error', 'W_FORTIFY_ANALYSIS_INCOMPLETE')]);
        self::assertFalse($error->incomplete());
        self::assertFalse($this->guard($error, false, false)->ok());
    }

    public function test_runtime_route_failure_blocks_both_policies_even_without_strict(): void
    {
        $audit = new ApplicationAuditResult('app', [], notices: [new AnalysisNotice('A_ROUTE_CONTEXT_UNAVAILABLE', 'runtime_routes_unavailable', 'routes', 1, 'Runtime route process failed.')]);
        self::assertTrue($audit->hasAnalysisFailure());
        foreach ([false, true] as $block) {
            self::assertFalse($this->guard($audit, false, $block)->ok());
            self::assertSame('fail', (new AgentOutput)->guard($this->guard($audit, false, $block))['audit']);
        }
    }

    public function test_task_summary_is_bounded_to_counts_and_excludes_source_messages(): void
    {
        $audit = new ApplicationAuditResult('app', [$this->finding('warn', 'W_FORTIFY_ANALYSIS_INCOMPLETE')], notices: [$this->notice(), $this->notice()]);
        self::assertSame(['status' => 'incomplete', 'policy' => 'warn', 'notice_total' => 2, 'incomplete_findings' => 1, 'reasons' => ['source_unavailable' => 2]], StandaloneAudit::analysisSummary($audit, false));
        self::assertSame('block', StandaloneAudit::analysisSummary($audit, true)['policy']);
    }

    private function guard(ApplicationAuditResult $audit, bool $strict, bool $block): ArchitectureGuardResult
    {
        return new ArchitectureGuardResult(new ArchitectureDoctorResult([], [], false), $audit, $strict, blockIncompleteAnalysis: $block);
    }

    private function finding(string $severity, string $code): AuditFinding
    {
        return new AuditFinding($severity, 'fixture', 'app/Probe.php', 1, 'Analysis is incomplete, private source message.', code: $code);
    }

    private function notice(): AnalysisNotice
    {
        return new AnalysisNotice('A_SOURCE_UNAVAILABLE', 'source_unavailable', 'app/Probe.php', 1, 'Private source message.');
    }
}
