<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Reach;

use GracjanKubicki\ArchitectureKit\Discovery\DiscoverySettings;
use GracjanKubicki\ArchitectureKit\Impact\ArchitectureImpact;
use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/** Bounded analysis followed by independently sized pages of one immutable report. */
final readonly class ArchitectureReach
{
    public function __construct(private Filesystem $files, private string $base) {}

    /** @return array<string, mixed> */
    public function inspect(string $subject = '', int $limit = 20, int $depth = 4, ?string $reportId = null, int $page = 1): array
    {
        if ($limit < 0 || $limit > 500 || $depth < 1 || $depth > 32 || $page < 1 || $page > 1000000 || ($reportId !== null && $subject !== '') || ($reportId === null && $page !== 1)) {
            return self::error('E_REACH_INPUT', 'Use limit 0..500, depth 1..32, page >=1. Continuation requires report_id without subject; new analysis starts at page 1.');
        }
        try {
            $store = new ReachReports($this->files, $this->base);
            if ($reportId !== null) {
                return $this->page($store->load($reportId), $reportId, $limit, $page);
            }
            $settings = DiscoverySettings::load($this->files, $this->base);
            $before = $store->signature($settings->exclude);
            $report = (new ArchitectureImpact($this->files, $this->base, $settings->scope, $settings->cache, $settings->fingerprint, reachMode: true))->inspect($subject, $settings->exclude, 1000, $depth);
            $report['cmd'] = 'reach';
            if (! $report['ok']) {
                return $report;
            }
            $after = $store->signature($settings->exclude);
            $report['reach'] = $this->summary($report, $before === $after && $settings->configStat === ($before['config/architectures.php'] ?? []));
            $report['analysis']['limit'] = 1000;
            $report['analysis']['expand'] = 'Read next pages using report_id. For analysis boundaries inspect boundary symbols, increase depth within 32, or narrow scope/increase memory and start a new report.';
            $report['next'] = ['read_next_page_of_same_report', 'inspect_uncertainty_and_boundary_sources', 'run_selected_tests'];
            $reportId = $store->save($report, $after, $settings->exclude);

            return $this->page($report, $reportId, $limit, $page);
        } catch (Throwable $error) {
            return self::error('E_REACH_REPORT', $error->getMessage());
        }
    }

    /** @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function summary(array $report, bool $fresh): array
    {
        $roles = $report['reach']['roles'];
        $code = [];
        $crossings = [];
        $crossingsLimited = false;
        foreach (['dependents', 'dependencies'] as $direction) {
            foreach (['resolved' => $report[$direction], 'possible' => $report['possible'][$direction], 'references' => $report['references'][$direction]] as $certainty => $rows) {
                $direct = count(array_filter($rows, static fn (array $row): bool => count($row['via']) === 1));
                $code[$direction][$certainty] = ['direct' => $direct, 'exclusively_indirect' => count($rows) - $direct, 'total' => count($rows)];
            }
        }
        foreach ($report['reach']['edges'] as $edge) {
            $fromClass = explode('::', $edge['from'], 2)[0];
            $toClass = explode('::', $edge['to'], 2)[0];
            $from = $roles[strtolower($fromClass)] ?? 'unknown';
            $to = $roles[strtolower($toClass)] ?? 'unknown';
            if ($from !== $to) {
                $key = json_encode([$edge['from'], $edge['to'], $edge['path'], $edge['line'], $edge['offset'] ?? null, $edge['kind'], $edge['certainty']], JSON_THROW_ON_ERROR);
                if (! isset($crossings[$key]) && (count($crossings) >= 1000 || ImpactExtractor::sourceLimit(0) !== null)) {
                    $crossingsLimited = true;

                    continue;
                }
                $crossings[$key] = [...$edge, 'from_role' => $from, 'to_role' => $to, 'is_violation' => false];
            }
        }
        $entries = ['declared' => [], 'possible' => []];
        foreach ($report['execution']['routes'] as $route) {
            $key = $route['id'] ?? json_encode([$route['source'], $route['verbs'], $route['uri'], $route['name'], $route['domain']], JSON_THROW_ON_ERROR);
            $entries[$route['certainty'] === 'possible' ? 'possible' : 'declared'][$key] = true;
        }
        foreach ($report['execution']['flows'] as $flow) {
            if (($flow['direction'] ?? '') === 'outgoing') {
                continue;
            }
            $key = $flow['entry']['route']['id'] ?? json_encode([$flow['entry']['kind'], $flow['entry']['symbol'], $flow['entry']['source'] ?? null], JSON_THROW_ON_ERROR);
            $entries[$flow['certainty'] === 'possible' ? 'possible' : 'declared'][$key] = true;
        }
        $data = [];
        foreach (['outgoing', 'consumers'] as $direction) {
            $unique = [];
            foreach ($report['data'][$direction] as $effect) {
                $key = $effect['id'] ?? json_encode([$effect['path'], $effect['line'], $effect['offset'], $effect['from'], $effect['table'], $effect['connection'], $effect['operation']], JSON_THROW_ON_ERROR);
                $unique[$key] = true;
            }
            $data[$direction] = count($unique);
        }
        $bounded = $crossingsLimited || $report['reach']['code_limited'] || $report['execution']['status'] === 'limit' || $report['data']['status'] === 'limit';
        $fresh = $fresh && $report['reach']['fresh'] && $report['execution']['fresh'] && $report['data']['fresh'];

        return ['counts' => ['code' => $code, 'execution_entries' => array_map('count', $entries), 'data_operations' => $data],
            'units' => ['code' => $report['subject']['kind'] === 'file' ? 'unique class/method/file symbols per certainty and direction' : (isset($report['subject']['requested_method']) ? 'unique method symbols per certainty and direction' : 'unique class symbols per certainty and direction'), 'execution_entries' => 'unique source entry declarations per certainty; dispatch is not execution', 'data_operations' => 'unique source operation IDs per direction; table is not execution adjacency'],
            'layer_crossings' => array_values($crossings), 'layer_crossings_limited' => $crossingsLimited, 'total_is_lower_bound' => $bounded || ! $fresh,
            'fresh' => $fresh, 'status' => $bounded ? 'limit' : (! $fresh || $report['analysis']['notice_total'] > 0 || $report['execution']['status'] === 'incomplete' || $report['data']['status'] === 'incomplete' ? 'incomplete' : 'complete'),
            'analysis_budget' => ['rows_per_channel' => 1000, 'edge_visits' => 10000, 'queue' => 1000, 'depth' => $report['analysis']['depth']],
            'limitations' => ['Counts apply only to recognized links in the declared scope/depth. Zero is not proof of no runtime links.', 'Possible and resolved are separate sets and may contain the same symbol; do not sum them as unique overall reach.', 'Layer crossings are informational, including unknown roles. They do not report rule violations.', 'Display truncation does not stop analysis. Analysis limits yield lower bounds.', 'Freshness uses PHP/composer file list, mtime and size. Equal-stat edits are not detected.', 'Metadata freshness inventories project PHP names without parsing or expanding audit scope. Vendor, node_modules, .git, .env and symlinks are excluded.']];
    }

    /** @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function page(array $report, string $id, int $limit, int $page): array
    {
        $paths = [['execution', 'authorization', 'checks'], ['execution', 'authorization', 'rules'], ['execution', 'authorization', 'outgoing'], ['execution', 'authorization', 'consumers'], ['execution', 'authorization', 'unresolved'], ['classification', 'symbols'], ['classification', 'module_relations'], ['dependents'], ['dependencies'], ['possible', 'dependents'], ['possible', 'dependencies'], ['possible', 'overrides'], ['references', 'dependents'], ['references', 'dependencies'], ['class_context', 'dependents'], ['class_context', 'dependencies'], ['tests'], ['analysis', 'notices'], ['execution', 'routes'], ['execution', 'flows'], ['execution', 'unresolved'], ['execution', 'flow_analysis', 'unresolved'], ['data', 'outgoing'], ['data', 'consumers'], ['data', 'unresolved'], ['reach', 'layer_crossings']];
        $max = 0;
        foreach ($paths as $path) {
            $rows = &$report;
            foreach ($path as $key) {
                $rows = &$rows[$key];
            }
            $max = max($max, count($rows));
            $rows = array_slice($rows, ($page - 1) * $limit, $limit);
            unset($rows);
        }
        $pages = $limit === 0 ? 1 : max(1, (int) ceil($max / $limit));
        if ($page > $pages) {
            return self::error('E_REACH_PAGE', 'Page is outside this report. Use a valid page or start a new report.');
        }
        $report['reach']['pagination'] = ['report_id' => $id, 'page' => $page, 'limit' => $limit, 'pages' => $pages, 'truncated' => $max > $page * $limit, 'next_page' => $limit > 0 && $page < $pages ? $page + 1 : null];

        return $report;
    }

    /** @return array<string, mixed> */
    public static function error(string $code, string $message): array
    {
        return ['v' => 1, 'cmd' => 'reach', 'ok' => false, 'm' => $code, 'msg' => $message, 'next' => ['fix_input_or_start_new_reach_report']];
    }
}
