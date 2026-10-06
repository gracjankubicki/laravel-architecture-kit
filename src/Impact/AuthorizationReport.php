<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Impact;

/** Compact rule summaries retain site counts independently of displayed paths. */
final readonly class AuthorizationReport
{
    /** @param list<string> $subjects
     * @return array<string, mixed>
     */
    public function inspect(ExecutionLinks $links, array $subjects, int $limit, int $depth, bool $fresh): array
    {
        $checks = array_column($links->authorizationChecks, 'symbol');
        $outgoing = (new PathTraversal)->find($subjects, $checks, $links->out, $links->unknown, $limit, $depth);
        $consumers = (new PathTraversal)->find($checks, $subjects, $links->out, $links->unknown, $limit, $depth);
        $selected = [];
        foreach ($outgoing['paths'] as $path) {
            $selected[strtolower($path['to'])] = true;
        }
        foreach ($consumers['paths'] as $path) {
            $selected[strtolower($path['from'])] = true;
        }
        $rules = [];
        $visits = 0;
        $limited = $links->limited || $outgoing['limited'] || $consumers['limited'];
        foreach ($links->authorizationChecks as $check) {
            foreach ($links->out[strtolower($check['symbol'])] ?? [] as $edge) {
                if (++$visits > 100000 || ImpactExtractor::sourceLimit(0) !== null) {
                    $limited = true;
                    break 2;
                }
                if (! ($edge['authorization'] ?? false)) {
                    continue;
                }
                $key = strtolower($edge['to']).'|'.$edge['kind'];
                $rules[$key] ??= ['symbol' => $edge['to'], 'kind' => $edge['kind'], 'registration' => $edge['registration'] ?? null, 'sites' => [], 'relevant' => false];
                $site = $check['source']['path'].':'.$check['source']['offset'];
                $rules[$key]['sites'][$site] = true;
                $rules[$key]['relevant'] = $rules[$key]['relevant'] || isset($selected[strtolower($check['symbol'])]);
            }
        }
        $summary = [];
        foreach ($rules as $rule) {
            if ($rule['relevant']) {
                $summary[] = ['symbol' => $rule['symbol'], 'kind' => $rule['kind'], 'registration' => $rule['registration'], 'recognized_site_count' => count($rule['sites']), 'count_is_lower_bound' => $limited || ! $fresh || $links->notices !== [], 'expand' => 'Use path from a caller or reach with continuation pages to inspect source routes.'];
            }
        }
        $notices = array_values(array_unique([...$links->notices, ...$outgoing['notices'], ...$consumers['notices']], SORT_REGULAR));
        $related = array_values(array_filter($links->authorizationChecks, fn ($check) => isset($selected[strtolower($check['symbol'])])));
        $truncated = $limited || count($summary) > $limit || count($related) > $limit || count($notices) > $limit;

        return ['checks' => array_slice($related, 0, $limit), 'rules' => array_slice($summary, 0, $limit), 'outgoing' => $outgoing['paths'], 'consumers' => $consumers['paths'], 'unresolved' => array_slice($notices, 0, $limit), 'totals' => ['checks' => count($related), 'rules' => count($summary), 'outgoing' => $outgoing['path_total'], 'consumers' => $consumers['path_total']], 'total_is_lower_bound' => $limited || ! $fresh, 'truncated' => $truncated, 'fresh' => $fresh, 'status' => $limited ? 'limit' : ($notices !== [] || ! $fresh ? 'incomplete' : ($related === [] ? 'none' : 'complete')), 'limitations' => ['Source witnesses do not decide access for a user. A missing check is not a security verdict.', 'Rules summarize distinct recognized source sites, not runtime requests or invocations.', 'Policy, hook, branch and exception conditions must be inspected on individual paths.', 'Impact reruns expand display; reach continuation pages preserve the original report and reject changed source metadata.']];
    }
}
