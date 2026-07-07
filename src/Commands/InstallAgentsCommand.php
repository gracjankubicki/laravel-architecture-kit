<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfigPath;
use GracjanKubicki\ArchitectureKit\Install\AgentInstaller;
use GracjanKubicki\ArchitectureKit\Install\AgentsDetector;
use GracjanKubicki\ArchitectureKit\Install\InstallResult;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

class InstallAgentsCommand extends Command
{
    protected $signature = 'architecture-kit:install-agents
        {--codex : Configure Codex}
        {--claude : Configure Claude Code}
        {--mcp : Install MCP configuration}
        {--hooks : Install hook configuration}';

    protected $description = 'Install or repair Architecture Kit MCP and hook configuration for AI coding agents.';

    public function handle(Filesystem $files): int
    {
        try {
            $runtime = (new ArchitectureConfig(ArchitectureConfigPath::resolve($files, base_path()), $files))->runtime();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $detector = new AgentsDetector($files, base_path());
        $agentNames = $this->agentNames($detector);
        $features = $this->features();

        $agents = $detector->resolve($agentNames);
        $installer = new AgentInstaller($files, base_path(), $runtime);
        $plan = $installer->plan($agents, $features['mcp'], $features['hooks']);

        if ($this->blocked($plan) !== []) {
            $this->error('Architecture Kit cannot install agent integration because invalid or incompatible files block generated targets.');
            $this->newLine();

            foreach ($this->blocked($plan) as $path) {
                $this->line("  blocked  {$path}");
            }

            $this->newLine();
            $this->line('Fix the blocking files, then run php artisan architecture-kit:install-agents again.');

            return self::FAILURE;
        }

        if (! $this->showPlan($plan)) {
            $this->info('No agent integration changes needed.');

            return self::SUCCESS;
        }

        if (! confirm('Continue?', default: true)) {
            $this->info('No changes were made.');

            return self::SUCCESS;
        }

        $installer->write($agents, $features['mcp'], $features['hooks']);

        $this->info('Architecture Kit agent integration installed.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function agentNames(AgentsDetector $detector): array
    {
        $names = [];

        if ((bool) $this->option('codex')) {
            $names[] = 'codex';
        }

        if ((bool) $this->option('claude')) {
            $names[] = 'claude_code';
        }

        if ($names !== []) {
            return $names;
        }

        $detected = $detector->detectedAgentNames();
        $default = $detected === [] ? ['codex', 'claude_code'] : $detected;

        return multiselect(
            label: 'Which AI agents should Architecture Kit configure?',
            options: collect($detector->agents())
                ->mapWithKeys(fn ($agent, string $name): array => [$name => $agent->displayName()])
                ->all(),
            default: $default,
            required: 'Select at least one agent.',
        );
    }

    /**
     * @return array{mcp: bool, hooks: bool}
     */
    private function features(): array
    {
        $mcp = (bool) $this->option('mcp');
        $hooks = (bool) $this->option('hooks');

        if ($mcp || $hooks) {
            return [
                'mcp' => $mcp,
                'hooks' => $hooks,
            ];
        }

        $features = multiselect(
            label: 'Which agent integrations should Architecture Kit install?',
            options: [
                'mcp' => 'MCP server config',
                'hooks' => 'Guard hooks',
            ],
            default: ['mcp', 'hooks'],
            required: 'Select at least one integration.',
        );

        return [
            'mcp' => in_array('mcp', $features, true),
            'hooks' => in_array('hooks', $features, true),
        ];
    }

    /**
     * @param  array{mcp: InstallResult, hooks: InstallResult, state: InstallResult}  $plan
     * @return array<int, string>
     */
    private function blocked(array $plan): array
    {
        return array_values(array_unique(array_merge(
            $plan['mcp']->blocked,
            $plan['hooks']->blocked,
            $plan['state']->blocked,
        )));
    }

    /**
     * @param  array{mcp: InstallResult, hooks: InstallResult, state: InstallResult}  $plan
     */
    private function showPlan(array $plan): bool
    {
        $changes = false;

        foreach ($plan as $area => $result) {
            foreach (['creates' => 'create', 'updates' => 'update'] as $property => $label) {
                foreach ($result->{$property} as $path) {
                    if (! $changes) {
                        $this->line('Planned agent integration changes:');
                        $changes = true;
                    }

                    $this->line(sprintf('  %-7s %-6s %s', $label, $area, $path));
                }
            }
        }

        return $changes;
    }
}
