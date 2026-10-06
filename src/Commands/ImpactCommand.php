<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Commands;

use GracjanKubicki\ArchitectureKit\Discovery\DiscoverySettings;
use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
use GracjanKubicki\ArchitectureKit\Impact\ImpactSchema;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Throwable;

final class ImpactCommand extends Command
{
    protected $signature = 'architecture-kit:impact
        {subject? : FQCN, short class name, project PHP path, or Class::method; omit with --table}
        {--agent : Output JSON for agents}
        {--limit=20 : Maximum rows per section, 0..500}
        {--depth=4 : Maximum dependency hops, 1..32}
        {--change= : Change mode: signature for Class::method, delete for a method/class/file, or move for a class/file}
        {--signature= : Proposed PHP method declaration without body; implies change=signature}
        {--target-class= : Target FQCN for change=move; rename only the selected declaration}
        {--target-path= : Project-relative PHP target path for change=move; relocate the entire file}
        {--table= : Find DATA uses of a table instead of a symbol}
        {--table-match= : Table name matching: exact (default) or contains}
        {--connection= : Table connection filter: default, dynamic, or named:name}
        {--operation= : Table effect filter: read, write, schema, or schema-read}
        {--schema : Output the JSON Schema}';

    protected $description = 'Inspect symbol relationships or database table uses without executing application code.';

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
                $state = DiscoverySettings::load($files, base_path());
                $result = (new ArchitectureImpact($files, base_path(), $state->scope, $state->cache, $state->fingerprint))
                    ->inspect((string) ($this->argument('subject') ?? ''), $state->exclude, (int) $limit, (int) $depth, $this->option('change'), $this->option('signature'), $this->option('target-class'), $this->option('target-path'), $this->option('table'), $this->option('table-match'), $this->option('connection'), $this->option('operation'));
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
        } elseif (isset($result['table_report'])) {
            $report = $result['table_report'];
            $this->info('Architecture Kit table uses');
            $this->line('Table: '.$report['query']['table'].' ['.$report['query']['match'].']; status: '.$report['status'].'; fresh: '.($report['fresh'] ? 'yes' : 'no'));
            $this->line('Totals: '.json_encode($report['totals']));
            foreach ($report['usages'] as $usage) {
                $this->line($usage['connection']['kind'].':'.($usage['connection']['name'] ?? '?').'/'.$usage['table'].' ['.$usage['kind'].', '.$usage['operation'].'] '.$usage['path'].':'.$usage['line']);
                foreach ($usage['paths'] as $path) {
                    $this->line('  '.$path['entry']['kind'].' '.$path['entry']['symbol']);
                    foreach ($path['via'] as $edge) {
                        $this->line('    '.$edge['from'].' -> '.$edge['to'].' ['.$edge['kind'].', '.($edge['mode'] ?? 'call').', '.($edge['timing'] ?? 'immediate').'] '.$edge['path'].':'.$edge['line']);
                        foreach ($edge['conditions'] as $condition) {
                            $this->line('      '.$condition);
                        }
                    }
                }
                foreach ($usage['conditions'] as $condition) {
                    $this->line('  '.$condition);
                }
                if ($usage['paths_truncated']) {
                    $this->warn('Usage paths are limited; inspect source boundaries.');
                }
            }
            foreach ($report['unresolved'] as $notice) {
                $this->warn($notice['path'].':'.$notice['line'].' '.$notice['reason']);
            }
            foreach ($report['limitations'] as $limitation) {
                $this->line($limitation);
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
            $data = $result['data'];
            $this->line('DATA: '.$data['status'].'; fresh: '.($data['fresh'] ? 'yes' : 'no'));
            foreach (['outgoing', 'consumers'] as $direction) {
                foreach ($data[$direction] as $effect) {
                    $this->line('  '.$direction.' '.$effect['connection']['kind'].':'.($effect['connection']['name'] ?? '?').'/'.$effect['table'].' ['.$effect['kind'].', '.$effect['operation'].'] '.$effect['path'].':'.$effect['line']);
                    foreach ($effect['via'] as $edge) {
                        $this->line('    '.$edge['from'].' -> '.$edge['to'].' ['.$edge['kind'].'] '.$edge['path'].':'.$edge['line']);
                    }
                }
            }
            foreach ($data['unresolved'] as $notice) {
                $this->warn($notice['path'].':'.$notice['line'].' '.$notice['reason']);
            }
            $http = $result['execution'];
            $this->line('HTTP declarations: '.$http['status'].'; fresh: '.($http['fresh'] ? 'yes' : 'no'));
            foreach ($http['routes'] as $route) {
                $this->line('  '.implode('|', $route['verbs'] ?? ['unknown']).' '.($route['uri'] ?? '(unknown URI)').' name='.($route['name'] ?? '(unnamed/unknown)').' domain='.($route['domain'] ?? '(default/unknown)').' middleware='.json_encode($route['middleware']).' ['.$route['certainty'].'] '.$route['source']['path'].':'.$route['source']['line']);
                foreach ($route['via'] as $edge) {
                    $this->line('    '.$edge['from'].' -> '.$edge['to'].' ['.$edge['kind'].', '.$edge['certainty'].'] '.$edge['path'].':'.$edge['line']);
                }
                foreach ($route['reasons'] as $reason) {
                    $this->line('    '.$reason);
                }
            }
            foreach ($http['unresolved'] as $notice) {
                $this->warn($notice['path'].':'.$notice['line'].' '.$notice['reason']);
            }
            foreach ($http['limitations'] as $limitation) {
                $this->line($limitation);
            }
            if ($http['truncated']) {
                $this->warn('HTTP report is limited. Inspect source notices and boundary symbols; totals may be lower bounds.');
            }
            $authorization = $http['authorization'];
            $this->line('Authorization: '.$authorization['status'].'; fresh: '.($authorization['fresh'] ? 'yes' : 'no'));
            foreach ($authorization['rules'] as $rule) {
                $this->line('  '.$rule['symbol'].' ['.$rule['kind'].']; recognized sites: '.$rule['recognized_site_count'].($rule['count_is_lower_bound'] ? ' (lower bound)' : ''));
            }
            foreach ($authorization['checks'] as $check) {
                $this->line('  '.$check['from'].' ['.$check['operation'].', '.$check['result'].', '.$check['usage'].', '.$check['denial_handling'].'] '.$check['source']['path'].':'.$check['source']['line']);
            }
            foreach (['outgoing', 'consumers'] as $direction) {
                foreach ($authorization[$direction] as $path) {
                    foreach ($path['via'] as $edge) {
                        $this->line('    '.$direction.' '.$edge['from'].' -> '.$edge['to'].' ['.$edge['kind'].'] '.$edge['path'].':'.$edge['line']);
                        foreach ($edge['conditions'] as $condition) {
                            $this->line('      '.$condition);
                        }
                    }
                }
            }
            foreach ($authorization['unresolved'] as $notice) {
                $this->warn($notice['path'].':'.$notice['line'].' '.$notice['reason']);
            }
            $this->line('Console, scheduler, job, event and model flows: '.$http['flow_analysis']['status'].'; fresh: '.($http['flow_analysis']['fresh'] ? 'yes' : 'no'));
            foreach ($http['flows'] as $flow) {
                $this->line('  '.$flow['entry']['symbol'].' -> '.$flow['target'].' ['.$flow['certainty'].']');
                foreach ($flow['via'] as $edge) {
                    $this->line('    '.$edge['from'].' -> '.$edge['to'].' ['.$edge['kind'].', '.($edge['mode'] ?? 'call').', '.($edge['timing'] ?? 'immediate').'] '.$edge['path'].':'.$edge['line']);
                    foreach ($edge['conditions'] as $condition) {
                        $this->line('      '.$condition);
                    }
                }
            }
            foreach ($http['flow_analysis']['unresolved'] as $notice) {
                $this->warn($notice['path'].':'.$notice['line'].' '.$notice['reason']);
            }
            if (isset($result['signature']) || isset($result['delete']) || isset($result['move'])) {
                $report = $result['signature'] ?? $result['delete'] ?? $result['move'];
                if (isset($result['move'])) {
                    $this->line('Move '.$report['mode'].': '.$report['source']['path'].' ['.$report['status'].']');
                    $this->line('Target class: '.($report['target']['class'] ?? 'unchanged').'; target path: '.($report['target']['path'] ?? 'unchanged'));
                } else {
                    $this->line(isset($result['signature']) ? 'Signature '.$report['mode'].': '.$report['declaration'].' ['.$report['status'].']' : 'Delete: '.implode(', ', $report['removed']).' ['.$report['status'].']');
                }
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
