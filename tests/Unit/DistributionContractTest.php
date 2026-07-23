<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class DistributionContractTest extends TestCase
{
    public function test_ci_keeps_write_access_out_of_validation_jobs_and_gates_badge_updates(): void
    {
        $root = dirname(__DIR__, 2);
        $contents = file_get_contents($root.'/.github/workflows/tests.yml');

        $this->assertIsString($contents);
        $this->assertStringNotContainsString('\\${{', $contents);

        $workflow = Yaml::parse($contents);

        $this->assertIsArray($workflow);
        $this->assertSame(['contents' => 'read'], $workflow['permissions']);

        $jobs = $workflow['jobs'];
        $lintCommands = array_column($jobs['lint']['steps'], 'run');
        $this->assertArrayNotHasKey('permissions', $jobs['lint']);
        $this->assertArrayNotHasKey('permissions', $jobs['tests']);
        $this->assertArrayNotHasKey('permissions', $jobs['coverage']);
        $this->assertContains('composer audit --locked --no-interaction', $lintCommands);
        $this->assertSame(['lint', 'tests', 'laravel-ai-contract', 'runtime-install', 'boost-composition', 'workbench-commands'], $jobs['coverage']['needs']);
        $this->assertSame(
            ['0.8.0', '^0.8', '0.9.0', '^0.9', '0.10.0', '^0.10'],
            $jobs['laravel-ai-contract']['strategy']['matrix']['ai'],
        );
        $runtimeSmoke = file_get_contents($root.'/tests/Smoke/runtime-install.sh');
        $boostSmoke = file_get_contents($root.'/tests/Smoke/boost-composition.sh');
        $workbenchSmoke = file_get_contents($root.'/tests/Smoke/workbench-commands.sh');
        $this->assertIsString($runtimeSmoke);
        $this->assertStringContainsString('composer install --no-dev', $runtimeSmoke);
        $this->assertIsString($boostSmoke);
        $this->assertStringContainsString('architecture-kit-laravel-ai/SKILL.md', $boostSmoke);
        $this->assertStringContainsString('architecture-kit-upgrade-laravel-ai-0-8-to-0-9/SKILL.md', $boostSmoke);
        $this->assertStringContainsString('architecture-kit-upgrade-laravel-ai-0-9-to-0-10/SKILL.md', $boostSmoke);
        $this->assertStringContainsString('ai-sdk-development/SKILL.md', $boostSmoke);
        $this->assertStringContainsString('laravel/ai:^0.10', $boostSmoke);
        $this->assertIsString($workbenchSmoke);
        $this->assertStringContainsString('architecture-kit:doctor --agent', $workbenchSmoke);
        $this->assertStringContainsString('architecture-kit:audit --agent', $workbenchSmoke);
        $this->assertStringContainsString('architecture-kit:plan --agent', $workbenchSmoke);
        $this->assertSame(['12.*', '13.*'], $jobs['workbench-commands']['strategy']['matrix']['laravel']);
        $this->assertStringContainsString('laravel/framework:${{ matrix.laravel }}', $jobs['workbench-commands']['steps'][2]['run']);
        $this->assertSame('bash tests/Smoke/workbench-commands.sh', $jobs['workbench-commands']['steps'][3]['run']);

        $lowest = array_values(array_filter(
            $jobs['tests']['strategy']['matrix']['include'],
            fn (array $entry): bool => ($entry['dependencies'] ?? null) === 'lowest',
        ));

        $this->assertCount(1, $lowest);
        $this->assertStringContainsString('--prefer-lowest --prefer-stable', $contents);

        $badge = $jobs['update-coverage-badge'];
        $this->assertSame(['coverage'], $badge['needs']);
        $this->assertSame("github.event_name == 'push' && github.ref == 'refs/heads/main'", $badge['if']);
        $this->assertSame(['contents' => 'write'], $badge['permissions']);

        $writeJobCommands = implode("\n", array_map(
            fn (array $step): string => (string) ($step['run'] ?? ''),
            $badge['steps'],
        ));

        $this->assertStringNotContainsString('composer ', $writeJobCommands);
        $this->assertStringNotContainsString('phpunit', $writeJobCommands);
    }

    public function test_public_metadata_and_archive_rules_match_the_product_contract(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $readme = file_get_contents($root.'/README.md');
        $attributes = file_get_contents($root.'/.gitattributes');

        $this->assertSame(
            'Laravel architecture guidance, application audit, and guard tooling for AI coding agents.',
            $composer['description'],
        );
        $this->assertIsString($readme);
        $this->assertStringContainsString('## Capabilities and enforcement', $readme);
        $this->assertStringContainsString('Guidance is intentionally broader than the rules that can be verified deterministically.', $readme);
        $this->assertStringContainsString('Architecture Kit is a runtime dependency', $readme);
        $this->assertStringNotContainsString('installed as a development dependency', $readme);
        $this->assertStringContainsString('`architecture-kit:plan` is read-only.', $readme);
        $this->assertStringContainsString('php artisan architecture-kit:plan --schema', $readme);
        $this->assertStringContainsString('php artisan architecture-kit:upgrade-plan --schema', $readme);
        $this->assertStringContainsString('MCP tool `plan-upgrade`', $readme);
        $this->assertStringContainsString('## Versioned package upgrade guides', $readme);
        $this->assertStringContainsString('architecture-kit-upgrade-laravel-ai-0-8-to-0-9', $readme);
        $this->assertStringContainsString('architecture-kit-upgrade-laravel-ai-0-9-to-0-10', $readme);
        $this->assertStringContainsString('`0.8 -> 0.9 -> 0.10`', $readme);
        $this->assertIsString($attributes);

        foreach ([
            '/.idea',
            '/art',
            '/bin/generate-coverage-badge',
            '/composer.lock',
            '/docs',
            '/implementation-plans',
            '/REPO-REVIEW.md',
            '/testbench.yaml',
            '/tests',
            '/vendor',
            '/workbench',
        ] as $developmentPath) {
            $this->assertStringContainsString($developmentPath.' export-ignore', $attributes);
        }
    }
}
