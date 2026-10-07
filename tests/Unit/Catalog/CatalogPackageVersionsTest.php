<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogPackageVersions;
use GracjanKubicki\ArchitectureKit\Catalog\ComposerCatalogExtractor;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CatalogPackageVersionsTest extends TestCase
{
    private function package(string $name, string $version, mixed $source, string $path = 'composer.lock'): CatalogFacts
    {
        $record = ['name' => $name, 'version' => $version];
        if ($source !== null) {
            $record['source'] = $source;
        }

        return (new ComposerCatalogExtractor)->extract(new FileContext($path, json_encode(['packages' => [$record]], JSON_THROW_ON_ERROR)));
    }

    public function test_all_baselines_require_matching_declared_references_and_keep_absence_explicit(): void
    {
        $this->assertSame(array_keys(CatalogPackageVersions::BASELINES), array_keys(CatalogPackageVersions::REFERENCES));
        foreach (CatalogPackageVersions::BASELINES as $name => $version) {
            $reference = CatalogPackageVersions::REFERENCES[$name];
            $facts = $this->package($name, $version, ['reference' => strtoupper($reference), 'url' => 'private-credential-sentinel']);
            $cached = CatalogFacts::fromArray($facts->path, $facts->toArray());
            $profile = CatalogPackageVersions::verified(new CatalogIndex([$cached]), $name);
            $this->assertNotNull($profile, $name);
            $this->assertSame($reference, $profile['sources'][0]['source_reference']);
            $this->assertSame('known', $profile['sources'][0]['reference_state']);
            $this->assertStringNotContainsString('private-credential-sentinel', json_encode($facts->toArray(), JSON_THROW_ON_ERROR));
            $withoutReference = CatalogPackageVersions::verified(new CatalogIndex([$this->package($name, $version, null)]), $name);
            $this->assertNotNull($withoutReference);
            $this->assertSame('absent', $withoutReference['sources'][0]['reference_state']);
            $wrong = new CatalogIndex([$this->package($name, $version, ['reference' => str_repeat('f', 40)])]);
            $this->assertNull(CatalogPackageVersions::verified($wrong, $name));
            $this->assertContains('composer_profile_reference_mismatch', array_column($wrong->diagnostics, 'code'));
        }
    }

    public function test_equal_versions_with_different_locked_and_installed_sources_are_explicitly_mismatched(): void
    {
        $name = 'livewire/livewire';
        $version = CatalogPackageVersions::BASELINES[$name];
        $locked = $this->package($name, $version, ['reference' => CatalogPackageVersions::REFERENCES[$name]]);
        $installed = $this->package($name, $version, ['reference' => str_repeat('f', 40)], 'vendor/composer/installed.json');
        $index = new CatalogIndex([$locked, $installed]);
        $this->assertContains('composer_reference_mismatch', array_column($index->diagnostics, 'code'));
        $this->assertNotContains('composer_version_mismatch', array_column($index->diagnostics, 'code'));
        $this->assertNull(CatalogPackageVersions::verified($index, $name));
        $matching = $this->package($name, $version, ['reference' => CatalogPackageVersions::REFERENCES[$name]], 'vendor/composer/installed.json');
        $index = new CatalogIndex([$locked, $matching]);
        $this->assertNotNull(CatalogPackageVersions::verified($index, $name));
        $this->assertNotContains('composer_reference_mismatch', array_column($index->diagnostics, 'code'));
    }

    public function test_inspected_mcp_releases_require_their_own_reference_and_reject_unknown_releases(): void
    {
        foreach (CatalogPackageVersions::MCP_REFERENCES as $version => $reference) {
            $facts = $this->package('laravel/mcp', $version, ['reference' => $reference]);
            $cached = CatalogFacts::fromArray($facts->path, $facts->toArray());
            $profile = CatalogPackageVersions::verified(new CatalogIndex([$cached]), 'laravel/mcp');
            $this->assertNotNull($profile, $version);
            $this->assertSame($version, $profile['version']);
            $this->assertSame($reference, $profile['sources'][0]['source_reference']);
            $wrong = new CatalogIndex([$this->package('laravel/mcp', $version, ['reference' => str_repeat('f', 40)])]);
            $this->assertNull(CatalogPackageVersions::verified($wrong, 'laravel/mcp'));
            $this->assertContains('composer_profile_reference_mismatch', array_column($wrong->diagnostics, 'code'));
        }
        $this->assertNull(CatalogPackageVersions::verified(new CatalogIndex([$this->package('laravel/mcp', '1.0.1', null)]), 'laravel/mcp'));
    }

    public function test_invalid_source_references_are_omitted_and_cannot_activate_a_known_profile(): void
    {
        foreach ([['reference' => 'private-reference-sentinel'], ['reference' => null], ['reference' => []], 'private-source-sentinel'] as $source) {
            $facts = $this->package('livewire/livewire', '4.4.5', $source);
            $index = new CatalogIndex([$facts]);
            $this->assertNull(CatalogPackageVersions::verified($index, 'livewire/livewire'));
            $this->assertContains('composer_reference_unknown', array_column($index->diagnostics, 'code'));
            $serialized = json_encode($facts->toArray(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('private-reference-sentinel', $serialized);
            $this->assertStringNotContainsString('private-source-sentinel', $serialized);
        }
    }

    public function test_corrupt_cached_source_reference_state_is_rejected(): void
    {
        $facts = $this->package('livewire/livewire', '4.4.5', null);
        $corrupt = $facts->toArray();
        foreach ($corrupt['elements'] as &$element) {
            if ($element['kind'] === 'composer-package') {
                $element['metadata']['reference_state'] = 'known';
            }
        }
        unset($element);
        $this->expectException(InvalidArgumentException::class);
        CatalogFacts::fromArray($facts->path, $corrupt);
    }

    public function test_unknown_package_with_an_unparseable_version_cannot_match_an_absent_baseline(): void
    {
        $facts = $this->package('vendor/custom', 'unparseable-version', null);
        $index = new CatalogIndex([$facts]);
        $this->assertContains('composer_version_unknown', array_column($index->diagnostics, 'code'));
        $this->assertNull(CatalogPackageVersions::verified($index, 'vendor/custom'));
    }
}
