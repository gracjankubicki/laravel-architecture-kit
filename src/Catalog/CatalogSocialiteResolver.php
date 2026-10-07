<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Impact\ImpactExtractor;

final class CatalogSocialiteResolver
{
    public const FLUENT = ['stateless', 'scopes', 'setscopes', 'redirecturl', 'with', 'sethttpclient', 'setrequest', 'enablepkce'];

    private const FACTORIES = ['Laravel\\Socialite\\Facades\\Socialite', 'Laravel\\Socialite\\Socialite', 'Laravel\\Socialite\\SocialiteManager', 'Laravel\\Socialite\\Contracts\\Factory'];

    private const PROVIDERS = ['Laravel\\Socialite\\Contracts\\Provider', 'Laravel\\Socialite\\Two\\AbstractProvider', 'Laravel\\Socialite\\One\\AbstractProvider'];

    public function __construct(private readonly CatalogIndex $index, private readonly CatalogCallResolver $calls) {}

    public function resolve(): void
    {
        $profile = CatalogPackageVersions::verified($this->index, 'laravel/socialite');
        $sites = [];
        foreach ($this->index->elements as $element) {
            if ($element['kind'] === 'socialite-operation') {
                $sites[$element['path']][$element['offset']][$element['metadata']['method']] = $element;
            }
        }
        foreach ($this->index->elements as $id => $element) {
            if ($element['kind'] !== 'class' || $profile === null) {
                continue;
            }
            foreach (self::PROVIDERS as $contract) {
                if ($this->index->hasContract($id, $contract) && $this->index->namedTypes($contract) === []) {
                    $this->index->elements[$id]['roles'][] = 'oauth-provider';
                    $this->index->elements[$id]['role_evidence'][] = ['role' => 'oauth-provider', 'basis' => 'source-contract', 'contract' => $contract,
                        'package' => 'laravel/socialite', 'version' => $profile['version'], 'package_sources' => $profile['sources'], 'path' => $element['path'], 'line' => $element['line']];
                    break;
                }
            }
        }
        $operations = 0;
        $edges = $this->index->relations;
        foreach ($edges as $call) {
            $method = strtolower($call['metadata']['method'] ?? '');
            if ($call['kind'] !== 'calls' || ! isset(SocialiteCatalogExtractor::PARAMETERS[$method]) || ! $this->provider($call['metadata']['receiver'])) {
                continue;
            }
            if (++$operations > 4096 || ImpactExtractor::sourceLimit(0) !== null) {
                $this->notice($call, 'Socialite composition reached its source budget.', 'catalog_limit');

                return;
            }
            $site = $sites[$call['path']][$call['metadata']['offset'] ?? -1][$method] ?? null;
            $shadowed = false;
            foreach ([...self::FACTORIES, ...self::PROVIDERS] as $factory) {
                $shadowed = $shadowed || $this->index->namedTypes($factory) !== [];
            }
            if ($profile === null || $shadowed || $site === null || ! $site['metadata']['valid'] || ($call['metadata']['form'] ?? null) !== 'instance') {
                $this->notice($call, 'Socialite version, argument shape or source dispatch is unresolved.');

                continue;
            }
            $driver = $site['metadata']['driver'];
            if ($driver === null) {
                $this->notice($call, 'Socialite provider selector is dynamic or requires source alias identity.');
            }
            $name = $driver ?? 'unresolved Socialite provider';
            $id = CatalogElement::resourceIdentity('oauth-provider', $driver ?? $call['path'].':'.$site['offset']);
            $provider = new CatalogElement($id, $name, 'oauth-provider', $call['line'], $call['end_line'], $site['offset'],
                metadata: ['logical_resource' => true, 'driver' => $driver, 'runtime_selection_known' => false]);
            if (! isset($this->index->elements[$id])) {
                $this->index->elements[$id] = [...$provider->toArray(), 'path' => $call['path'], 'knowledge' => 'static', 'role_evidence' => [],
                    'sources' => [['path' => $call['path'], 'line' => $call['line'], 'end_line' => $call['end_line']]]];
                $this->index->names[strtolower($name)][] = $id;
            } else {
                $this->index->elements[$id]['sources'][] = ['path' => $call['path'], 'line' => $call['line'], 'end_line' => $call['end_line']];
            }
            $this->index->addRelation(['from' => $call['from'], 'to' => $id, 'kind' => $method === 'redirect' ? 'socialite-redirect' : ($method === 'refreshtoken' ? 'socialite-refresh-token' : 'socialite-fetch-user'),
                'path' => $call['path'], 'line' => $call['line'], 'end_line' => $call['end_line'], 'knowledge' => 'static', 'resolution' => 'conditional',
                'metadata' => ['package' => 'laravel/socialite', 'version' => $profile['version'], 'package_sources' => $profile['sources'], 'method' => $method,
                    'driver' => $driver, 'runtime_driver_selection_required' => true, 'runtime_standard_provider_required' => true,
                    'runtime_provider_method_required' => true,
                    'uncached_user_required' => $method === 'user', 'valid_oauth_state_required_if_stateful' => $method === 'user',
                    'external_exchange_required_if_standard_provider' => $method !== 'redirect', 'execution_proven' => false]]);
        }
    }

    private function provider(string $receiver, int $depth = 0): bool
    {
        if (! str_starts_with($receiver, '@return:')) {
            return array_intersect($this->calls->receiverCandidates($receiver)['types'], self::PROVIDERS) !== [];
        }
        if ($depth >= 16) {
            return false;
        }
        $value = json_decode(substr($receiver, 8), true, 32);
        if (! is_array($value) || count($value) !== 3 || ! is_string($value[0]) || ! is_string($value[1])) {
            return false;
        }
        $method = strtolower($value[1]);
        if (in_array($method, ['driver', 'with'], true) && in_array($value[0], self::FACTORIES, true)) {
            return true;
        }

        return in_array($method, self::FLUENT, true) && $this->provider($value[0], $depth + 1);
    }

    /** @param array<string, mixed> $site */
    private function notice(array $site, string $message, string $code = 'package_socialite_analysis'): void
    {
        $this->index->diagnostics[] = ['code' => $code, 'message' => $message, 'path' => $site['path'], 'line' => $site['line'], 'subject' => $site['from']];
    }
}
