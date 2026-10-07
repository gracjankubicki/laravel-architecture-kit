<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

/** Only a validated standard SDK setter binding proves its fluent provider return. */
final class CatalogAiProviderSetterReturns
{
    /** @var array<string, array<string, array<int, list<array<string, mixed>>>>> */
    private array $returns = [];

    /** @var array<string, array<string, mixed>> */
    private array $bindings = [];

    /** @param array<string, mixed> $binding */
    public function add(array $binding): void
    {
        $metadata = $binding['metadata'];
        if ($binding['kind'] === 'ai-provider-gateway-binding' && ($metadata['runtime_standard_provider_required'] ?? false)
            && in_array(strtolower($metadata['method'] ?? ''), CatalogAiGateways::SETTERS, true)
            && is_int($metadata['setter_end_offset'] ?? null)) {
            $this->returns[$binding['path']][$binding['parent']][$metadata['setter_end_offset']][] = $binding;
            $this->bindings[$binding['id']] = $binding;
        }
    }

    /** @param array<string, mixed> $call
     * @return array{channels: array<string, list<string>>, limited: bool}
     */
    public function configuration(array $call, string $type): array
    {
        $channels = $current = [];
        $limited = false;
        foreach ($this->candidates($call) as $binding) {
            if (strcasecmp($binding['metadata']['provider_type'], $type) !== 0) {
                continue;
            }
            $limited = $limited || ($binding['metadata']['setter_history_limited'] ?? false);
            foreach ($binding['metadata']['prior_setter_bindings'] ?? [] as $method => $ids) {
                $channels[$method] = array_values(array_unique([...($channels[$method] ?? []), ...$ids]));
            }
            $method = strtolower($binding['metadata']['method']);
            $current[$method][] = $binding['id'];
        }
        $channels = [...$channels, ...$current];
        if ($limited || array_sum(array_map('count', $channels)) > 128) {
            return ['channels' => [], 'limited' => true];
        }

        return ['channels' => $channels, 'limited' => false];
    }

    /** @param array{channels: array<string, list<string>>, limited: bool} $configuration
     * @return list<array<string, mixed>>
     */
    public function gatewayBindings(array $configuration, string $method): array
    {
        $bindings = [];
        foreach ($configuration['channels'][$method] ?? [] as $id) {
            if (isset($this->bindings[$id])) {
                $bindings[] = $this->bindings[$id];
            }
        }

        return $bindings;
    }

    /** @param array<string, mixed> $call
     * @return list<array<string, mixed>>
     */
    public function candidates(array $call): array
    {
        $receiver = $call['metadata']['receiver'];
        $descriptor = str_starts_with($receiver, '@return:') ? json_decode(substr($receiver, 8), true, 32) : null;
        if (! is_array($descriptor) || count($descriptor) !== 3 || ! is_string($descriptor[1] ?? null)
            || ! in_array(strtolower($descriptor[1]), CatalogAiGateways::SETTERS, true)) {
            return [];
        }

        return array_values(array_filter($this->returns[$call['path']][$call['from']][$call['metadata']['receiver_origin'] ?? -1] ?? [],
            fn ($binding) => strcasecmp($binding['metadata']['method'], $descriptor[1]) === 0));
    }
}
