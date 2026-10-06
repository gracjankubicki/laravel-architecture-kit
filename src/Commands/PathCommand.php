<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\Discovery\DiscoverySettings;
use GracjanKubicki\ArchitectureKit\Impact\ArchitecturePath;
use GracjanKubicki\ArchitectureKit\Impact\PathSchema;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final class PathCommand extends Command
{
    protected $signature = 'architecture-kit:path
        {from? : Class, Class::method or project PHP path}
        {to? : Class, Class::method or project PHP path}
        {--agent : Output JSON for agents}
        {--limit=20 : Maximum paths and notices per channel, 0..500}
        {--depth=8 : Maximum hops, 1..32}
        {--schema : Output the JSON Schema}';

    protected $description = 'Find bounded source-only paths between two endpoints, separately for dependencies and execution.';

    public function handle(Filesystem $files): int
    {
        if ($this->option('schema')) {
            $this->line(json_encode(PathSchema::get(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        try {
            $limit = $this->option('limit');
            $depth = $this->option('depth');
            if (! ctype_digit((string) $limit) || ! ctype_digit((string) $depth)) {
                $result = ArchitecturePath::error('E_PATH_LIMIT_INVALID', 'Limits must be non-negative integers.');
            } else {
                $state = DiscoverySettings::load($files, base_path());
                $result = (new ArchitecturePath($files, base_path(), $state->scope, $state->cache, $state->fingerprint))->inspect((string) $this->argument('from'), (string) $this->argument('to'), $state->exclude, (int) $limit, (int) $depth);
            }
        } catch (Throwable $exception) {
            $result = ArchitecturePath::error('E_PATH_FAILED', $exception->getMessage());
        }
        if ($this->option('agent')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } elseif (! $result['ok']) {
            $this->error($result['msg']);
            foreach ($result['candidates'] ?? [] as $candidate) {
                $this->line($candidate['name'].' '.$candidate['path'].':'.$candidate['line']);
            }
        } else {
            foreach (['dependencies', 'execution'] as $channel) {
                $report = $result[$channel];
                $this->line($channel.': '.$report['status'].'; found: '.($report['found'] ? 'yes' : 'no').'; fresh: '.($report['fresh'] ? 'yes' : 'no'));
                foreach (['paths', 'external_boundaries'] as $group) {
                    foreach ($report[$group] as $path) {
                        $this->line('  '.$group.': '.$path['from'].' -> '.$path['to']);
                        foreach ($path['via'] as $edge) {
                            $this->line('    '.$edge['from'].' -> '.$edge['to'].' ['.$edge['kind'].', '.$edge['certainty'].'] '.$edge['path'].':'.$edge['line']);
                            foreach ($edge['conditions'] as $condition) {
                                $this->line('      '.$condition);
                            }
                        }
                    }
                }
                foreach ($report['notices'] as $notice) {
                    $this->warn($notice['path'].':'.$notice['line'].' '.$notice['reason']);
                }
            }
            foreach ($result['analysis']['limitations'] as $limitation) {
                $this->line($limitation);
            }
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
