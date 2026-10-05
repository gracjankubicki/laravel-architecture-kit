<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\Discovery\ArchitectureDiscovery;
use GracjanKubicki\ArchitectureKit\Discovery\SearchSchema;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

final class SearchCommand extends Command
{
    protected $signature = 'architecture-kit:search {query? : Literal name or path fragment} {--kind= : Declaration kind} {--limit=20 : Maximum candidates and notices, 0..500} {--agent : Output JSON} {--schema : Output JSON Schema}';

    protected $description = 'Find source declarations and choose an explicit impact/path selector.';

    public function handle(Filesystem $files): int
    {
        if ($this->option('schema')) {
            $this->line(json_encode(SearchSchema::get(), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $limit = (string) $this->option('limit');
        $result = ctype_digit($limit)
            ? (new ArchitectureDiscovery($files, base_path()))->search((string) $this->argument('query'), $this->option('kind'), (int) $limit)
            : ArchitectureDiscovery::error('E_SEARCH_INPUT', 'Use an integer limit 0..500.');
        if ($this->option('agent')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } elseif (! $result['ok']) {
            $this->error($result['msg']);
        } else {
            $this->line($result['status'].'; candidates: '.$result['total'].($result['total_is_lower_bound'] ? ' or more' : '').'; truncated: '.($result['truncated'] ? 'yes' : 'no'));
            foreach ($result['candidates'] as $row) {
                $this->line($row['kind'].' '.$row['name'].' '.$row['path'].':'.$row['line'].' -> '.($row['selector'] ?? 'unsupported').' ['.$row['selector_scope'].']');
                foreach ($row['notes'] as $note) {
                    $this->line('  '.$note);
                }
            }
            foreach ($result['notices'] as $notice) {
                $this->warn($notice['path'].':'.$notice['line'].' '.$notice['reason']);
            }
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
