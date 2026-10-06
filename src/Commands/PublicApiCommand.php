<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\PublicApi\ArchitecturePublicApi;
use GracjanKubicki\ArchitectureKit\PublicApi\PublicApiSchema;
use Illuminate\Console\Command;

final class PublicApiCommand extends Command
{
    protected $signature = 'architecture-kit:public-api {from? : Git revision before the change} {--to=working : Git revision or working sources} {--public-path=* : Limit to literal package-relative public directories/files} {--current-version= : Current stable x.y.z version} {--zero-policy= : 0.x policy: breaking-minor or breaking-major} {--limit=50 : Display at most 0..500 changes/notices} {--agent : Output JSON} {--schema : Output JSON Schema}';

    protected $description = 'Compare package PHP, Laravel and MCP contracts without checkout or source execution.';

    public function handle(): int
    {
        if ($this->option('schema')) {
            $this->line(json_encode(PublicApiSchema::get(), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $limit = (string) $this->option('limit');
        $result = ctype_digit($limit)
            ? (new ArchitecturePublicApi(base_path()))->compare((string) $this->argument('from'), (string) $this->option('to'), $this->option('public-path'), $this->option('current-version'), $this->option('zero-policy'), (int) $limit)
            : ArchitecturePublicApi::error('E_PUBLIC_API_INPUT', 'Limit must be an integer 0..500.');
        if ($this->option('agent')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } elseif (! $result['ok']) {
            $this->error($result['msg']);
        } else {
            $this->line('Public API '.$result['analysis']['status'].'; fresh: '.($result['analysis']['fresh'] ? 'yes' : 'no'));
            $this->line(json_encode($result['totals'], JSON_THROW_ON_ERROR));
            foreach ($result['changes'] as $row) {
                $this->line($row['verdict'].' '.$row['element'].' '.implode(' ', $row['reasons']));
            }
            $this->line('Version recommendation: '.($result['semver']['recommendation'] ?? 'unresolved').'. '.$result['semver']['reason']);
            $this->line('Use --agent for source identities, partial analysis, notices and before/after evidence. This report does not approve a release.');
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
