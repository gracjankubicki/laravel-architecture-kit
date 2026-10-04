<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
use GracjanKubicki\ArchitectureKit\Impact\ImpactSchema;
use GracjanKubicki\ArchitectureKit\ProjectState;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final class ImpactCommand extends Command
{
    protected $signature = 'architecture-kit:impact
        {subject? : FQCN, short class name, project PHP path, or Class::method}
        {--agent : Output JSON for agents}
        {--limit=20 : Maximum rows per section, 0..500}
        {--depth=4 : Maximum dependency hops, 1..32}
        {--change= : Change mode: signature for Class::method, or delete for a method/class/file}
        {--signature= : Proposed PHP method declaration without body; implies change=signature}
        {--schema : Output the JSON Schema}';

    protected $description = 'Inspect static class and method relationships before a change, without executing application code.';

    public function handle(Filesystem $files): int
    {
        if ($this->option('schema')) {
            $this->line(json_encode(ImpactSchema::get(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        try {
            $limit = $this->option('limit');
            $depth = $this->option('depth');
            if ((! is_int($limit) && (! is_string($limit) || ! ctype_digit($limit))) || (! is_int($depth) && (! is_string($depth) || ! ctype_digit($depth)))) {
                $result = ArchitectureImpact::error('E_IMPACT_LIMIT_INVALID', 'Limits must be non-negative integers.');
            } else {
                $state = ProjectState::load($files, dirname(__DIR__, 2), base_path());
                $result = (new ArchitectureImpact($files, base_path(), $state->auditScope, $state->graphCache, $state->graphConfiguration()))
                    ->inspect((string) ($this->argument('subject') ?? ''), $state->exclude, (int) $limit, (int) $depth, $this->option('change'), $this->option('signature'));
            }
        } catch (Throwable $exception) {
            $result = ArchitectureImpact::error('E_IMPACT_FAILED', $exception->getMessage());
        }
        if ($this->option('agent')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } elseif (! $result['ok']) {
            $this->error($result['msg']);
            foreach ($result['candidates'] ?? [] as $candidate) {
                $this->line($candidate['name'].'  '.$candidate['path'].':'.$candidate['line']);
            }
        } else {
            $this->info('Architecture Kit Impact');
            $this->line('Subject: '.($result['subject']['declaration']['symbol'] ?? $result['subject']['name']));
            $this->line('Analysis: '.$result['analysis']['status'].'; cache: '.$result['cache']);
            foreach (['Dependents' => $result['dependents'], 'Dependencies' => $result['dependencies'],
                'Possible dependents through contracts' => $result['possible']['dependents'], 'Possible dependencies through contracts' => $result['possible']['dependencies'],
                'Overrides to inspect' => $result['possible']['overrides'], 'Callable references, not executions' => [...$result['references']['dependents'], ...$result['references']['dependencies']]] as $label => $rows) {
                $this->line($label.':');
                foreach ($rows as $row) {
                    $this->line('  '.$row['symbol'].'  '.$row['path'].':'.$row['line']);
                    foreach ($row['via'] ?? [] as $edge) {
                        $this->line('    '.$edge['from'].' -> '.$edge['to'].' ['.$edge['kind'].', '.$edge['certainty'].'] '.$edge['path'].':'.$edge['line']);
                    }
                }
                if ($rows === []) {
                    $this->line('  none detected');
                }
            }
            $this->line('Class context, not method calls:');
            foreach ([...$result['class_context']['dependents'], ...$result['class_context']['dependencies']] as $edge) {
                $this->line('  '.$edge['from'].' -> '.$edge['to'].' ['.$edge['kind'].'] '.$edge['path'].':'.$edge['line']);
            }
            $this->line('Test candidates, not coverage or PASS:');
            foreach ($result['tests'] as $test) {
                $this->line('  '.$test['path'].' ['.$test['basis'].']');
            }
            if (isset($result['signature']) || isset($result['delete'])) {
                $report = $result['signature'] ?? $result['delete'];
                $this->line(isset($result['signature']) ? 'Signature '.$report['mode'].': '.$report['declaration'].' ['.$report['status'].']' : 'Delete: '.implode(', ', $report['removed']).' ['.$report['status'].']');
                foreach (['breaking', 'check', 'compatible'] as $group) {
                    $this->line(strtoupper($group).':');
                    foreach ($report[$group] as $row) {
                        $this->line('  '.$row['symbol'].' '.$row['path'].':'.$row['line'].' '.implode('; ', $row['reasons']));
                    }
                }
                $this->warn('No breaking rows does not prove that the change is safe. Inspect uncertainty and run selected tests.');
            }
            foreach ($result['analysis']['notices'] as $notice) {
                $this->warn($notice['path'].':'.$notice['line'].' '.$notice['reason']);
            }
            foreach ($result['analysis']['limitations'] as $limitation) {
                $this->line($limitation);
            }
            if ($result['analysis']['truncated']) {
                $this->warn($result['analysis']['expand']);
            }
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
