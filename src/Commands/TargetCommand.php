<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\Target\ArchitectureTarget;
use GracjanKubicki\ArchitectureKit\Target\TargetDefinition;
use GracjanKubicki\ArchitectureKit\Target\TargetSchema;
use Illuminate\Console\Command;

final class TargetCommand extends Command
{
    protected $signature = 'architecture-kit:target {subject? : Class, method or future PHP file} {--limit=50 : Display 0..500 rows per channel} {--agent : Output JSON} {--schema : Output JSON Schema}';

    protected $description = 'Compare source architecture with declared target and propose migration without writes.';

    public function handle(): int
    {
        if ($this->option('schema')) {
            $this->line(json_encode(TargetSchema::get(), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $limit = (string) $this->option('limit');
        $result = ctype_digit($limit)
            ? (new ArchitectureTarget(base_path()))->inspect((string) $this->argument('subject'), (int) $limit)
            : ArchitectureTarget::error('E_TARGET_INPUT', 'Limit must be an integer 0..500.');
        if ($this->option('agent')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } elseif (! $result['ok']) {
            $this->error($result['msg']);
        } elseif (! $result['configured']) {
            $this->line('No architecture target declared. Add '.TargetDefinition::PATH.'.');
        } else {
            $this->line('Target '.$result['target']['id'].' '.$result['target']['version'].'; fresh: '.($result['analysis']['fresh'] ? 'yes' : 'no'));
            $this->line(json_encode($result['totals'], JSON_THROW_ON_ERROR));
            foreach ($result['migration_order'] as $row) {
                $this->line(json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }
            $this->line('Gate '.$result['gate']['mode'].': '.($result['gate']['ok'] ? 'passed' : 'failed').'. Use --agent for current audit, unresolved facts and reference candidate.');
        }

        return $result['ok'] && ($result['gate']['ok'] ?? true) ? self::SUCCESS : self::FAILURE;
    }
}
