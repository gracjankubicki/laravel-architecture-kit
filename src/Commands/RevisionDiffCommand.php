<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\Revision\ArchitectureRevisionDiff;
use GracjanKubicki\ArchitectureKit\Revision\RevisionDiffSchema;
use Illuminate\Console\Command;

final class RevisionDiffCommand extends Command
{
    protected $signature = 'architecture-kit:revision-diff {from? : Git revision before the change} {--to=working : Git revision or working sources} {--shared-config= : Use before or after configuration for both states} {--pair=* : Explicit old=new symbol identity} {--limit=50 : Display at most 0..500 rows per channel} {--agent : Output JSON} {--schema : Output JSON Schema}';

    protected $description = 'Compare architecture across source revisions without checkout, execution or project cache writes.';

    public function handle(): int
    {
        if ($this->option('schema')) {
            $this->line(json_encode(RevisionDiffSchema::get(), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $pairs = [];
        foreach ($this->option('pair') as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) !== 2 || isset($pairs[$parts[0]])) {
                $result = ArchitectureRevisionDiff::error('E_REVISION_INPUT', 'Use one --pair=old=new per explicit symbol pair.');
                break;
            }
            $pairs[$parts[0]] = $parts[1];
        }
        if (! isset($result)) {
            $limit = (string) $this->option('limit');
            $result = ctype_digit($limit) ? (new ArchitectureRevisionDiff(base_path()))->compare(
                (string) $this->argument('from'), (string) $this->option('to'), $this->option('shared-config'), $pairs, (int) $limit,
            ) : ArchitectureRevisionDiff::error('E_REVISION_INPUT', 'Limit must be an integer 0..500.');
        }
        if ($this->option('agent')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } elseif (! $result['ok']) {
            $this->error($result['msg']);
        } else {
            $this->line('Revision difference '.$result['analysis']['status'].'; fresh: '.($result['analysis']['fresh'] ? 'yes' : 'no'));
            $this->line(json_encode($result['totals'], JSON_THROW_ON_ERROR));
            foreach (['symbols', 'structure', 'execution', 'http', 'data', 'transitions', 'configuration', 'rule_sources'] as $channel) {
                foreach ($result['changes'][$channel] as $row) {
                    $this->line($channel.' '.json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                }
            }
            $this->line('Use --agent for identities, candidate mappings, source witnesses and notices. Differences do not judge architecture quality or runtime compatibility.');
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
