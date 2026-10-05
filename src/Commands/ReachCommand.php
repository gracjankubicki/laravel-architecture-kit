<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\Reach\ArchitectureReach;
use GracjanKubicki\ArchitectureKit\Reach\ReachSchema;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

final class ReachCommand extends Command
{
    protected $signature = 'architecture-kit:reach {subject? : Class, method or project PHP file} {--limit=20 : Display rows per section, 0..500} {--depth=4 : Analysis depth, 1..32} {--report= : Read a saved report without a subject} {--page=1 : Page of the same report} {--agent : Output JSON} {--schema : Output JSON Schema}';

    protected $description = 'Count recognized reach in both directions and page one immutable source report.';

    public function handle(Filesystem $files): int
    {
        if ($this->option('schema')) {
            $this->line(json_encode(ReachSchema::get(), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $numbers = [(string) $this->option('limit'), (string) $this->option('depth'), (string) $this->option('page')];
        $result = count(array_filter($numbers, 'ctype_digit')) === 3
            ? (new ArchitectureReach($files, base_path()))->inspect((string) $this->argument('subject'), (int) $numbers[0], (int) $numbers[1], $this->option('report'), (int) $numbers[2])
            : ArchitectureReach::error('E_REACH_INPUT', 'Use integer limit, depth and page.');
        if ($this->option('agent')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } elseif (! $result['ok']) {
            $this->error($result['msg']);
        } else {
            $this->line('Reach '.$result['reach']['status'].'; fresh: '.($result['reach']['fresh'] ? 'yes' : 'no').'; counts are '.($result['reach']['total_is_lower_bound'] ? 'lower bounds' : 'recognized totals'));
            $this->line(json_encode($result['reach']['counts'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $this->line(json_encode($result['reach']['pagination'], JSON_THROW_ON_ERROR));
            foreach (['dependents', 'dependencies'] as $direction) {
                foreach ($result[$direction] as $row) {
                    $this->line($direction.' '.$row['symbol'].' '.$row['path'].':'.$row['line']);
                }
            }
            $this->line('Use --agent to inspect all evidence, uncertain links, entrypoints, DATA and boundaries. Zero is not proof of no runtime links.');
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
