<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

/** Exact research baselines. A version entry alone does not prove an integration. */
final class CatalogPackageVersions
{
    public const BASELINES = [
        'saloonphp/saloon' => '4.0.0.0',
        'inertiajs/inertia-laravel' => '3.3.4.0',
        'livewire/livewire' => '4.4.5.0',
        'laravel/fortify' => '1.39.0.0',
        'laravel/ai' => '0.11.2.0',
        'laravel/mcp' => '0.8.2.0',
        'laravel/scout' => '11.7.0.0',
        'laravel/pennant' => '1.26.0.0',
        'laravel/cashier' => '16.8.0.0',
        'laravel/socialite' => '5.31.0.0',
        'laravel/horizon' => '5.49.0.0',
        'laravel/reverb' => '1.11.1.0',
    ];

    public const REFERENCES = [
        'saloonphp/saloon' => '1307b1d72cacdd2c9c20978cdf7a0b720b4bf3bb',
        'inertiajs/inertia-laravel' => '15fb5a7b2f984780ff968d9da3787aef6138326d',
        'livewire/livewire' => '10aa0b5ee44c99b5bce0f78ad265bbcf0e74abdc',
        'laravel/fortify' => 'b1fc50707bbe007fd92165d8b7d460ab549b355a',
        'laravel/ai' => 'ee2c5162838d440c4e2e629ea93c8c87e838eaed',
        'laravel/mcp' => '0c32bf369c6432cab21458f9f4479da33a49ba37',
        'laravel/scout' => '176a97da0a33bbd66bc4eaa2884249bf629d3d31',
        'laravel/pennant' => 'a343096b4b01a23a5dfadf92b0907f710134d5e0',
        'laravel/cashier' => '3741d81d0e2b7ca8de52c2f9a272d30edfa741b9',
        'laravel/socialite' => 'f721b2cbec327ab820bd6aabea6ab211cfcc9f08',
        'laravel/horizon' => '6dce8a97b426be5305adad537ad4b6a99de61b31',
        'laravel/reverb' => '52ce5fd88cd1d7eacfdcf6b91cea4704cc546f27',
    ];

    /** Shared Agent/Promptable/Tool/HasTools source contracts inspected per published patch. */
    public const AI_REFERENCES = [
        '0.8.0.0' => '7da9fd8cf7b66c755902f77498232c938b52af10',
        '0.8.1.0' => 'b23bc857576d7c4b3bb52ffd8be936ae74005d23',
        '0.9.0.0' => '1091772a6c2dba104c741286b4a73cc71a47cd00',
        '0.9.1.0' => '2760a62bff6ab515cdf10222f61b7973356450e1',
        '0.10.0.0' => 'afff3b05bb5d944c68eed58b670c5e1a414b648e',
        '0.10.1.0' => '47f0ffdb86639f710eb93fcdf1bd07635599f270',
        '0.10.2.0' => '71b90141894a409de0929c4e1c522bbf7a706bfb',
        '0.10.3.0' => 'c3848aae389f45c605eefb0dda5bd5fa7df76eaa',
        '0.11.0.0' => 'dd08142f0c4dc4d5544521ce3fc4a1b9c2fe748c',
        '0.11.1.0' => '47b171613da075ed9327cebb2c7f58e0654251bd',
        '0.11.2.0' => 'ee2c5162838d440c4e2e629ea93c8c87e838eaed',
    ];

    /** Shared Server/member/attribute/handler contracts inspected at these exact releases. */
    public const MCP_REFERENCES = [
        '0.8.2.0' => '0c32bf369c6432cab21458f9f4479da33a49ba37',
        '0.9.0.0' => '3d365d5db3493c806d190f3404cd7431634ca4e1',
        '0.9.1.0' => 'a08884d79a95c5143498507aec5badf751cdbec4',
        '0.9.2.0' => '2bb70c3662dfc2e2ff55c38a10ddf55bed2443b3',
        '0.9.3.0' => '534b29f18418673033ec918c5a840b6ce9c08327',
        '0.9.4.0' => '7ca5b923630118696602d14348cd0466a5e853ec',
        '0.9.5.0' => '923d8d8cd9ed46766d1a8b5269f8b4afc4c91c53',
        '0.9.6.0' => '57767d61fac5963bc90cffdaf371d1c687331ac5',
        '1.0.0.0' => 'cfa4f38f82873eeb6848527883545f98f871e229',
    ];

    public static function inspectedReference(string $package, string $version): ?string
    {
        if ($package === 'laravel/ai') {
            return self::AI_REFERENCES[$version] ?? null;
        }
        if ($package === 'laravel/mcp') {
            return self::MCP_REFERENCES[$version] ?? null;
        }

        return $version === (self::BASELINES[$package] ?? null) ? (self::REFERENCES[$package] ?? null) : null;
    }

    /** @return array{version: string, sources: list<array<string, mixed>>}|null */
    public static function verified(CatalogIndex $index, string $package): ?array
    {
        if (! isset(self::BASELINES[$package], self::REFERENCES[$package])) {
            return null;
        }
        $versions = $sources = [];
        foreach ($index->elements as $element) {
            if ($element['kind'] !== 'composer-package' || $element['name'] !== $package) {
                continue;
            }
            $version = $element['metadata']['normalized_version'] ?? null;
            $reference = is_string($version) ? self::inspectedReference($package, $version) : null;
            if ($reference === null) {
                return null;
            }
            $versions[] = $version;
            $state = $element['metadata']['reference_state'] ?? 'unknown';
            if ($state === 'unknown' || $state === 'known' && ($element['metadata']['source_reference'] ?? null) !== $reference) {
                return null;
            }
            $sources[] = ['path' => $element['path'], 'line' => $element['line'], 'end_line' => $element['end_line'],
                'package' => $package, 'state' => $element['metadata']['state'], 'reference_state' => $state,
                'source_reference' => $element['metadata']['source_reference'] ?? null];
        }
        if ($versions === [] || count(array_unique($versions)) !== 1) {
            return null;
        }

        return ['version' => $versions[0], 'sources' => $sources];
    }
}
