<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Standalone;

use GracjanKubicki\ArchitectureKit\Context\GraphPage;

/** Explicit public shape adapter, called after dispatch/formatting, before transmission. */
final class RuntimeResult
{
    /** @param array<string, mixed> $result
     * @param array<string, mixed> $runtime
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> */
    public static function adapt(string $operation, array $result, array $runtime, array $arguments = []): array
    {
        if ($operation === 'occurrence') {
            // Internal DTO payload is unpacked with named constructor arguments by the finding resolver.
            return $result;
        }
        $confirmation = $runtime['confirmation'];
        $freshness = $confirmation['freshness'];
        $result['runtime_semantics'] = 2;
        $result['freshness'] = $freshness;
        $result['confirmation'] = $confirmation;
        $fresh = ConfirmationState::legacyFresh($freshness);
        // Only these documented slots represent whole-map freshness. Domain records are untouched.
        $paths = match ($operation) {
            'discovery' => [[]],
            'path' => [['analysis'], ['dependencies'], ['execution']],
            'impact', 'impact-pages', 'reach' => [['analysis'], ['reach'], ['execution'], ['data']],
            default => [],
        };
        foreach ($paths as $path) {
            if ($path === []) {
                $result['fresh'] = $fresh;
            } elseif (isset($result[$path[0]]) && is_array($result[$path[0]])) {
                $result[$path[0]]['fresh'] = $fresh;
                $result[$path[0]]['freshness'] = $freshness;
            }
        }
        if (isset($result['analysis']) && is_array($result['analysis'])) {
            $result['analysis']['runtime_semantics'] = 2;
            $result['analysis']['freshness'] = $freshness;
        }

        return in_array($operation, ['search', 'graph'], true) ? GraphPage::withRuntime($result, $runtime, $arguments) : $result;
    }
}
