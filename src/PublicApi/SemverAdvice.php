<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\PublicApi;

final class SemverAdvice
{
    public const ZERO_POLICIES = ['breaking-minor', 'breaking-major'];

    /** @param list<array<string, mixed>> $changes
     * @return array<string, mixed> */
    public static function forChanges(array $changes, ?string $version, ?string $zeroPolicy, bool $complete): array
    {
        $breaking = array_values(array_filter($changes, static fn (array $row): bool => $row['verdict'] === 'breaking'));
        $extensions = array_values(array_filter($changes, static fn (array $row): bool => $row['verdict'] === 'compatible'));
        $checks = count(array_filter($changes, static fn (array $row): bool => $row['verdict'] === 'check'));
        $recommendation = null;
        $reason = 'No proved public declaration change. Absence of API changes does not prove a patch release.';
        if ($version === null || ! preg_match('/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D', $version, $match)) {
            $reason = 'Provide the current stable x.y.z version; no release component is guessed.';
        } elseif ($match[1] === '0' && $zeroPolicy === null) {
            $reason = 'A 0.x project needs an explicit release policy; no component is guessed.';
        } elseif ($breaking !== []) {
            $recommendation = $match[1] === '0' && $zeroPolicy === 'breaking-minor' ? 'minor' : 'major';
            $reason = 'Proved incompatibilities require this release component under the selected project policy.';
        } elseif ($extensions !== []) {
            $recommendation = 'minor';
            $reason = 'Recognized public extensions establish a minimum minor component; unresolved behaviour may require more.';
        } elseif ($checks > 0 || ! $complete) {
            $reason = 'Unresolved changes or incomplete sources prevent a release recommendation.';
        }

        return ['current_version' => $version, 'zero_policy' => $zeroPolicy, 'recommendation' => $recommendation,
            'minimum_only' => $recommendation !== null, 'release_ready' => false,
            'reason' => $reason, 'evidence' => array_column($breaking !== [] ? $breaking : $extensions, 'element'),
            'check_count' => $checks, 'source_complete' => $complete];
    }
}
