<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Revision;

use InvalidArgumentException;
use RuntimeException;

/** Source-only comparison shared by CLI and MCP. */
final readonly class ArchitectureRevisionDiff
{
    public function __construct(private string $basePath) {}

    /** @param array<string, string> $manualPairs
     * @return array<string, mixed> */
    public function compare(string $from, string $to = 'working', ?string $sharedConfig = null, array $manualPairs = [], int $limit = 50): array
    {
        if ($limit < 0 || $limit > 500 || ! in_array($sharedConfig, [null, 'before', 'after'], true) || count($manualPairs) > 1000) {
            return self::error('E_REVISION_INPUT', 'Use limit 0..500, shared_config before/after or omit it, and at most 1000 manual pairs.');
        }
        foreach ($manualPairs as $old => $new) {
            if (! is_string($old) || ! is_string($new) || strlen($old) > 1000 || strlen($new) > 1000 || $old === '' || $new === '') {
                return self::error('E_REVISION_INPUT', 'manual_pairs must map old symbol names to new symbol names.');
            }
        }
        try {
            $sources = new RevisionSources($this->basePath);
            $old = $sources->capture($from);
            $oldStats = $this->stats($old);
            $new = $sources->capture($to);
            $newStats = $this->stats($new);
            $oldConfig = RevisionConfiguration::from($old);
            $newConfig = RevisionConfiguration::from($new);
            $ownConfigurations = ['before' => $oldConfig->values, 'after' => $newConfig->values];
            if ($sharedConfig === 'before') {
                $newConfig = $oldConfig;
            } elseif ($sharedConfig === 'after') {
                $oldConfig = $newConfig;
            }
            $before = RevisionFacts::collect($old, $oldConfig);
            $after = RevisionFacts::collect($new, $newConfig, $before->reuse);
            $changes = RevisionChanges::compare($before, $after, $manualPairs);
            $comparisonNotices = $changes['notices'];
            unset($changes['notices']);
            $notices = [...array_map(static fn (array $notice): array => ['side' => 'before', ...$notice], $before->notices),
                ...array_map(static fn (array $notice): array => ['side' => 'after', ...$notice], $after->notices), ...$comparisonNotices];
            $channelCompleteness = [];
            foreach ($before->channels as $channel => $complete) {
                $channelCompleteness[$channel] = ['before_complete' => $complete, 'after_complete' => $after->channels[$channel]];
            }
            foreach ($comparisonNotices as $notice) {
                if (isset($notice['channel'], $notice['side'])) {
                    $channelCompleteness[$notice['channel']][$notice['side'].'_complete'] = false;
                }
            }
            $oldFresh = $sources->isFresh($old) && $oldStats === $this->stats($old);
            $newFresh = $sources->isFresh($new) && $newStats === $this->stats($new);
            if (! $oldFresh || ! $newFresh) {
                $notices[] = ['code' => 'E_REVISION_STALE', 'path' => '', 'message' => 'Working sources changed during comparison; rerun the report.'];
            }
            $totals = [];
            $truncated = false;
            foreach (['symbols', 'structure', 'execution', 'http', 'data', 'transitions', 'configuration', 'candidates', 'pairs', 'rule_sources'] as $channel) {
                $totals[$channel] = count($changes[$channel]);
                $truncated = $truncated || $totals[$channel] > $limit;
                $changes[$channel] = array_slice($changes[$channel], 0, $limit);
            }
            $totals['notices'] = count($notices);
            $notices = array_slice($notices, 0, $limit);

            return ['v' => 1, 'cmd' => 'revision-diff', 'ok' => true,
                'sources' => ['before' => [...$old->identity(), 'fresh' => $oldFresh], 'after' => [...$new->identity(), 'fresh' => $newFresh]],
                'analysis' => ['status' => $before->notices === [] && $after->notices === [] && $comparisonNotices === [] && $oldFresh && $newFresh ? 'complete' : 'incomplete',
                    'fresh' => $oldFresh && $newFresh, 'lower_bounds' => $before->notices !== [] || $after->notices !== [] || $comparisonNotices !== [] || ! $oldFresh || ! $newFresh,
                    'display_truncated' => $truncated || $totals['notices'] > $limit,
                    'configuration_mode' => $sharedConfig === null ? 'per_state' : 'shared_'.$sharedConfig,
                    'channels' => $channelCompleteness,
                    'before_complete' => $before->notices === [], 'after_complete' => $after->notices === []],
                'changes' => $changes, 'totals' => $totals, 'notices' => $notices,
                'configuration_sources' => $ownConfigurations,
                'metrics' => ['before' => $before->metrics, 'after' => $after->metrics],
                'limitations' => ['Source differences are not runtime identity, compatibility or architecture quality verdicts.',
                    'Historic dynamic configuration and external declarations remain unresolved; current configuration is never substituted.',
                    'Working freshness checks path, mtime and size, with content revalidation. Equal-stat edits are outside the stat guarantee.',
                    'Scope changes do not prove source deletion. Candidate mappings require explicit manual_pairs.',
                    'Costs are measured for this invocation without an SLA. Compatible file graph facts are reused in memory without writing project cache.'],
                'next' => $oldFresh && $newFresh ? ['inspect:changes_and_notices', 'confirm:candidate_pairs'] : ['rerun:revision-diff']];
        } catch (InvalidArgumentException $error) {
            return self::error('E_REVISION_INPUT', $error->getMessage());
        } catch (RuntimeException $error) {
            return self::error('E_REVISION_SOURCE', $error->getMessage());
        }
    }

    /** @return array<string, array<int>|null> */
    private function stats(SourceSnapshot $source): array
    {
        if ($source->state !== 'working') {
            return [];
        }
        $states = [];
        foreach ($source->paths as $path) {
            if (! SnapshotInputs::safe($path)) {
                continue;
            }
            $cursor = $this->basePath;
            $linked = false;
            foreach (explode('/', $path) as $part) {
                $cursor .= '/'.$part;
                clearstatcache(true, $cursor);
                if (is_link($cursor)) {
                    $linked = true;
                    break;
                }
            }
            $stat = $linked ? false : @lstat($cursor);
            $states[$path] = $stat === false ? null : [$stat['mtime'], $stat['size']];
        }

        return $states;
    }

    /** @return array<string, mixed> */
    public static function error(string $code, string $message): array
    {
        return ['v' => 1, 'cmd' => 'revision-diff', 'ok' => false, 'm' => $code, 'msg' => $message,
            'next' => ['fix:source_or_input', 'rerun:revision-diff']];
    }
}
