<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

use GracjanKubicki\ArchitectureKit\Discovery\DiscoverySettings;
use GracjanKubicki\ArchitectureKit\Reach\ReachReports;
use Illuminate\Filesystem\Filesystem;
use Throwable;

/** A saved proposal report is paged without rebuilding hypothetical state. */
final readonly class ImpactPages
{
    public function __construct(private Filesystem $files, private string $base) {}

    /** @return array<string, mixed> */
    public function inspect(string $subject = '', int $limit = 20, int $depth = 4, ?string $change = null, ?string $signature = null, ?string $targetClass = null, ?string $targetPath = null, ?string $reportId = null, int $page = 1): array
    {
        if ($limit < 0 || $limit > 500 || $depth < 1 || $depth > 32 || $page < 1 || $page > 1000000 || ($reportId === null && $page !== 1) || ($reportId !== null && ($subject !== '' || $change !== null || $signature !== null || $targetClass !== null || $targetPath !== null))) {
            return ArchitectureImpact::error('E_IMPACT_PAGE_INPUT', 'Start at page 1 with a subject and proposal. Continue using only report_id, page and display limit.');
        }
        try {
            $store = new ReachReports($this->files, $this->base);
            if ($reportId !== null) {
                $saved = $store->load($reportId);
                if (! is_array($saved['impact'] ?? null)) {
                    return ArchitectureImpact::error('E_IMPACT_PAGE_INPUT', 'This report belongs to another tool. Start a new impact report.');
                }
                $result = $saved['impact'];
            } else {
                $settings = DiscoverySettings::load($this->files, $this->base);
                $before = $store->signature($settings->exclude);
                $result = (new ArchitectureImpact($this->files, $this->base, $settings->scope, $settings->cache, $settings->fingerprint, reachMode: true))->inspect($subject, $settings->exclude, 1000, $depth, $change, $signature, $targetClass, $targetPath);
                if (! $result['ok']) {
                    return $result;
                }
                $after = $store->signature($settings->exclude);
                $fresh = $before === $after && $settings->configStat === ($before['config/architectures.php'] ?? []) && $result['reach']['fresh'] && $result['execution']['fresh'] && $result['data']['fresh'];
                // Internal traversal details are not an extra public analysis channel.
                unset($result['reach']);
                $result['analysis']['limit'] = 500;
                $result['analysis']['expand'] = 'Continue this immutable report with pagination.next. Analysis is bounded to 1000 rows per channel; totals at analysis limits are lower bounds.';
                $result['normalized_intent'] = ['subject' => $result['subject'], 'change' => $change ?? ($signature === null ? 'general' : 'signature'), 'signature' => $signature, 'target_class' => $targetClass, 'target_path' => $targetPath, 'depth' => $depth];
                $reportId = $store->save(['reach' => ['fresh' => $fresh], 'impact' => $result], $after, $settings->exclude);
            }

            return $this->page($result, $reportId, $limit, $page);
        } catch (Throwable $error) {
            return ArchitectureImpact::error('E_IMPACT_REPORT', $error->getMessage());
        }
    }

    /** @param array<string, mixed> $report
     * @return array<string, mixed> */
    private function page(array $report, string $id, int $limit, int $page): array
    {
        $paths = [['dependents'], ['dependencies'], ['tests'], ['analysis', 'notices'], ['possible', 'dependents'], ['possible', 'dependencies'], ['possible', 'overrides'], ['references', 'dependents'], ['references', 'dependencies'], ['class_context', 'dependents'], ['class_context', 'dependencies'], ['execution', 'routes'], ['execution', 'flows'], ['execution', 'unresolved'], ['execution', 'flow_analysis', 'unresolved'], ['data', 'outgoing'], ['data', 'consumers'], ['data', 'unresolved']];
        foreach (['checks', 'rules', 'outgoing', 'consumers', 'unresolved'] as $key) {
            $paths[] = ['execution', 'authorization', $key];
        }
        $paths[] = ['classification', 'symbols'];
        $paths[] = ['classification', 'module_relations'];
        if (isset($report['signature_overview'])) {
            $paths[] = ['signature_overview', 'methods'];
        }
        foreach (['delete', 'signature', 'move'] as $change) {
            if (isset($report[$change])) {
                foreach (['breaking', 'check', 'compatible'] as $key) {
                    $paths[] = [$change, $key];
                }
            }
        }
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
            return ArchitectureImpact::error('E_IMPACT_PAGE_INPUT', 'Page is outside this report.');
        }
        $report['pagination'] = ['report_id' => $id, 'page' => $page, 'limit' => $limit, 'pages' => $pages, 'truncated' => $max > $page * $limit, 'analysis_rows_per_channel' => 1000, 'next' => $limit > 0 && $page < $pages ? ['report_id' => $id, 'page' => $page + 1, 'limit' => $limit] : null];

        return $report;
    }
}
