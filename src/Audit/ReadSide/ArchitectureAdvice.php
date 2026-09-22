<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\ReadSide;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\AuditSuggestion;

/**
 * Turns observed endpoint composition into non-blocking placement advice.
 */
final readonly class ArchitectureAdvice
{
    /**
     * @param  array<int, Architecture|string>  $enabled
     * @param  list<array{kind: string, path: string, line: int, detail: string, trace: list<string>}>  $observations
     * @param  array<string, array{type: string, line: int, path: string}>  $services
     * @param  array<string, mixed>|null  $route
     * @return list<AuditSuggestion>
     */
    public static function forEndpoint(
        array $enabled,
        array $observations,
        array $services,
        string $path,
        int $line,
        ?array $route,
    ): array {
        $suggestions = [];
        $usesAction = (bool) array_filter(
            $observations,
            fn (array $observation): bool => (bool) array_filter(
                $observation['trace'],
                fn (string $trace): bool => str_starts_with($trace, 'App\\Actions\\'),
            ),
        );

        if (! $usesAction) {
            $write = array_values(array_filter($observations, fn (array $observation): bool => in_array($observation['kind'], ['write', 'effect'], true)));
            if ($write !== []) {
                $effect = $write[0];
                $suggestions[] = new AuditSuggestion(
                    code: 'S_MOVE_WRITE_TO_ACTION',
                    architecture: Architecture::Actions->value,
                    enabled: self::enabled($enabled, Architecture::Actions),
                    path: $effect['path'],
                    line: $effect['line'],
                    message: 'Move the write workflow into an Action and keep the controller as the HTTP adapter.',
                    reason: 'The endpoint reaches '.$effect['detail'].' through application logic owned by the controller or a service.',
                    trace: $effect['trace'],
                    route: $route,
                );
            }
        }

        $hasWrite = (bool) array_filter($observations, fn (array $observation): bool => in_array($observation['kind'], ['write', 'effect'], true));
        if ($services !== [] && ! $hasWrite) {
            $service = array_values($services)[0];
            $queryObjects = self::enabled($enabled, Architecture::QueryObjects);
            $servicesEnabled = self::enabled($enabled, Architecture::Services);
            if ($queryObjects || ! $servicesEnabled) {
                $suggestions[] = new AuditSuggestion(
                    code: 'S_MOVE_READ_TO_QUERY_OBJECT',
                    architecture: Architecture::QueryObjects->value,
                    enabled: $queryObjects,
                    path: $service['path'],
                    line: $service['line'],
                    message: 'Move reusable read composition into a Query Object.',
                    reason: 'The endpoint composes a reusable read through '.$service['type'].'. Enabling Query Objects is a project decision and is not performed by this suggestion.',
                    trace: [],
                    route: $route,
                );
            }
        }

        return self::deduplicate($suggestions);
    }

    /**
     * @param  array<int, Architecture|string>  $enabled
     */
    private static function enabled(array $enabled, Architecture $architecture): bool
    {
        return in_array($architecture, $enabled, true) || in_array($architecture->value, $enabled, true);
    }

    /**
     * @param  list<AuditSuggestion>  $suggestions
     * @return list<AuditSuggestion>
     */
    private static function deduplicate(array $suggestions): array
    {
        $unique = [];
        foreach ($suggestions as $suggestion) {
            $key = json_encode([
                $suggestion->path,
                $suggestion->line,
                $suggestion->architecture,
                $suggestion->trace,
                $suggestion->route['id'] ?? null,
            ], JSON_THROW_ON_ERROR);
            $unique[$key] ??= $suggestion;
        }

        return array_values($unique);
    }
}
