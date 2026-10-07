<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Catalog\JsonSourcePositions;
use PHPUnit\Framework\TestCase;

final class ComposerCatalogTest extends TestCase
{
    public function test_malformed_metadata_diagnostics_point_to_source_values(): void
    {
        $manifest = <<<'JSON'
{
  "name": "invalid",
  "require": {
    "vendor/package": "not a constraint!"
  },
  "require-dev": false,
  "autoload": {
    "psr-4": {
      "App\\": [
        "../outside/"
      ]
    }
  },
  "autoload-dev": false
}
JSON;
        $facts = (new ProjectGraphBuilder(catalog: true))->collect(new FileContext('composer.json', $manifest))->catalog;
        $this->assertSame([2, 4, 6, 10, 14], array_map(fn ($row) => $row->line, $facts->diagnostics));
        $this->assertSame([], array_values(array_filter($facts->elements, fn ($row) => in_array($row->kind, ['composer-dependency', 'autoload-mapping'], true))));
        $installed = <<<'JSON'
{
  "dev-package-names": false,
  "packages": [
    {
      "name": "vendor/package",
      "version": "unrecognizable!",
      "extra": {
        "laravel": {
          "providers": [
            42
          ]
        }
      }
    }
  ]
}
JSON;
        $facts = (new ProjectGraphBuilder(catalog: true))->collect(new FileContext('vendor/composer/installed.json', $installed))->catalog;
        $this->assertSame([2, 6, 10], array_map(fn ($row) => $row->line, $facts->diagnostics));
        $package = array_values(array_filter($facts->elements, fn ($row) => $row->kind === 'composer-package'))[0];
        $this->assertNull($package->metadata['development']);
        $this->assertNull($package->metadata['normalized_version']);
        $this->assertSame([], array_values(array_filter($facts->relations, fn ($row) => $row->kind === 'declares-package-provider')));
    }

    public function test_json_positions_follow_last_duplicate_keys_and_ignore_payload_tokens(): void
    {
        $source = "{\n\"target\": 1,\n\"payload\": \"\\\"target\\\": 999\",\n\"target\": 2,\n\"na\\u006de\": [\n\"value\"\n]\n}";
        $positions = new JsonSourcePositions($source);
        $this->assertSame(4, $positions->span(['target'])['line']);
        $this->assertSame('2', substr($source, $positions->span(['target'])['offset'], 1));
        $this->assertSame(6, $positions->span(['name', 0])['line']);
        $this->assertFalse($positions->limited);
        $facts = (new ProjectGraphBuilder(catalog: true))->collect(new FileContext('composer.lock', json_encode(array_fill(0, 50001, 1), JSON_THROW_ON_ERROR)))->catalog;
        $this->assertContains('catalog_limit', array_map(fn ($row) => $row->code, $facts->diagnostics));
        $this->assertFalse($facts->cacheable());
    }

    public function test_json_evidence_points_to_dependency_autoload_and_provider_values(): void
    {
        $manifest = "{\n  \"require\": {\n    \"vendor/package\": \"^1.2\"\n  },\n  \"autoload\": {\n    \"psr-4\": {\n      \"App\\\\\": [\n        \"app/\"\n      ]\n    }\n  }\n}";
        $lock = "{\n  \"packages\": [\n    {\n      \"name\": \"vendor/package\",\n      \"version\": \"1.2.3\",\n      \"extra\": {\n        \"laravel\": {\n          \"providers\": [\n            \"App\\\\Provider\"\n          ]\n        }\n      }\n    }\n  ]\n}";
        $graph = (new ProjectGraphBuilder(catalog: true))->build([new FileContext('composer.json', $manifest), new FileContext('composer.lock', $lock)]);
        $index = new CatalogIndex($graph->catalogFacts);
        $dependency = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'composer-dependency'))[0];
        $this->assertSame(3, $dependency['line']);
        $this->assertSame('"^1.2"', substr($manifest, $dependency['offset'], 6));
        $autoload = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'autoload-mapping'))[0];
        $this->assertSame(8, $autoload['line']);
        $this->assertSame('"app/"', substr($manifest, $autoload['offset'], 6));
        $package = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'composer-package'))[0];
        $this->assertSame([3, 13], [$package['line'], $package['end_line']]);
        $provider = array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'declares-package-provider'))[0];
        $this->assertSame(9, $provider['line']);
        $template = array_values(array_filter($graph->catalogFacts[1]->relations, fn ($row) => $row->kind === 'http-template'))[0];
        $this->assertSame('"App\\\\Provider"', substr($lock, $template->metadata['offset'], 15));
    }

    public function test_root_requirements_and_autoload_are_declarations_without_execution(): void
    {
        $manifest = ['name' => 'example/project', 'require' => ['php' => '^8.3', 'vendor/runtime' => '^1.2'], 'require-dev' => ['vendor/testing' => '^2.0'],
            'autoload' => ['psr-4' => ['App\\' => ['app/', 'modules/']], 'psr-0' => ['Legacy_' => 'legacy/'], 'classmap' => ['legacy/classes/'], 'files' => ['app/helpers.php'], 'exclude-from-classmap' => ['/tests/**']],
            'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']], 'scripts' => ['autoload' => 'script-secret']];
        $graph = (new ProjectGraphBuilder(catalog: true))->build([new FileContext('composer.json', json_encode($manifest, JSON_THROW_ON_ERROR)),
            new FileContext('composer.lock', '{"packages":[{"name":"vendor/runtime","version":"1.2.3"}]}')]);
        $index = new CatalogIndex($graph->catalogFacts);
        $dependencies = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'composer-dependency'));
        $this->assertSame(['php', 'vendor/runtime', 'vendor/testing'], array_column($dependencies, 'name'));
        $this->assertSame([false, false, true], array_column(array_column($dependencies, 'metadata'), 'development'));
        $this->assertSame([true, false, false], array_column(array_column($dependencies, 'metadata'), 'platform'));
        $autoload = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'autoload-mapping'));
        $this->assertCount(7, $autoload);
        $this->assertSame('tests/', $autoload[6]['metadata']['source_path']);
        $manifestNode = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'composer-manifest'))[0];
        $this->assertSame('example/project', $manifestNode['metadata']['package_name']);
        $versions = array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'references-package-version'));
        $this->assertCount(1, $versions);
        $this->assertFalse($versions[0]['metadata']['constraint_evaluated']);
        $this->assertNotContains('calls', array_column($index->relations, 'kind'));
        $this->assertStringNotContainsString('script-secret', json_encode($graph->catalogFacts));
        $this->assertSame([], $index->diagnostics);
    }

    public function test_empty_psr_namespace_can_map_project_root_without_executing_it(): void
    {
        $facts = (new ProjectGraphBuilder(catalog: true))->collect(new FileContext('composer.json', '{"autoload":{"psr-4":{"":""}}}'))->catalog;
        $mappings = array_values(array_filter($facts->elements, fn ($row) => $row->kind === 'autoload-mapping'));
        $this->assertCount(1, $mappings);
        $this->assertSame('', $mappings[0]->metadata['namespace']);
        $this->assertSame('', $mappings[0]->metadata['source_path']);
        $this->assertSame([], $facts->diagnostics);
    }

    public function test_manifest_budget_counts_empty_namespace_declarations(): void
    {
        $namespaces = [];
        for ($i = 0; $i < 10001; $i++) {
            $namespaces['Empty'.$i.'\\'] = [];
        }
        $facts = (new ProjectGraphBuilder(catalog: true))->collect(new FileContext('composer.json', json_encode(['autoload' => ['psr-4' => $namespaces]], JSON_THROW_ON_ERROR)))->catalog;
        $this->assertContains('catalog_limit', array_map(fn ($row) => $row->code, $facts->diagnostics));
        $this->assertFalse($facts->cacheable());
    }

    public function test_malformed_requirements_and_unsafe_autoload_paths_are_explicit(): void
    {
        $manifest = ['require' => ['invalid' => '^1', 'vendor/invalid' => ['secret']], 'autoload' => ['psr-4' => ['App\\' => ['../outside/', '/absolute/', 'safe/']]], 'autoload-dev' => 'invalid'];
        $facts = (new ProjectGraphBuilder(catalog: true))->collect(new FileContext('composer.json', json_encode($manifest, JSON_THROW_ON_ERROR)))->catalog;
        $this->assertContains('composer_metadata', array_map(fn ($row) => $row->code, $facts->diagnostics));
        $this->assertContains('composer_autoload_boundary', array_map(fn ($row) => $row->code, $facts->diagnostics));
        $mappings = array_values(array_filter($facts->elements, fn ($row) => $row->kind === 'autoload-mapping'));
        $this->assertCount(1, $mappings);
        $this->assertSame('safe/', $mappings[0]->metadata['source_path']);
    }

    public function test_locked_installed_versions_and_development_states_remain_distinct(): void
    {
        foreach (['1.2.3' => false, '1.2.4' => true] as $version => $mismatch) {
            $files = [new FileContext('composer.lock', json_encode(['packages' => [['name' => 'vendor/runtime', 'version' => 'v1.2.3']], 'packages-dev' => [['name' => 'vendor/testing', 'version' => 'dev-main']]], JSON_THROW_ON_ERROR)),
                new FileContext('vendor/composer/installed.json', json_encode(['packages' => [['name' => 'vendor/runtime', 'version' => $version], ['name' => 'vendor/testing', 'version' => 'dev-main']], 'dev-package-names' => ['vendor/testing']], JSON_THROW_ON_ERROR))];
            $index = new CatalogIndex((new ProjectGraphBuilder(catalog: true))->build($files)->catalogFacts);
            $this->assertSame($mismatch, in_array('composer_version_mismatch', array_column($index->diagnostics, 'code'), true));
            $packages = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'composer-package'));
            $this->assertSame([false, true, false, true], array_column(array_column($packages, 'metadata'), 'development'));
            $this->assertSame(['locked', 'locked', 'installed', 'installed'], array_column(array_column($packages, 'metadata'), 'state'));
            if ($mismatch) {
                $diagnostic = array_values(array_filter($index->diagnostics, fn ($row) => $row['code'] === 'composer_version_mismatch'))[0];
                $this->assertSame(['composer.lock', 'vendor/composer/installed.json'], array_column($diagnostic['sources'], 'path'));
            }
        }
        $legacy = new CatalogIndex((new ProjectGraphBuilder(catalog: true))->build([new FileContext('vendor/composer/installed.json', '[{"name":"vendor/runtime","version":"1.2.3"}]')])->catalogFacts);
        $package = array_values(array_filter($legacy->elements, fn ($row) => $row['kind'] === 'composer-package'))[0];
        $this->assertNull($package['metadata']['development']);
    }

    public function test_json_package_metadata_and_providers_preserve_states_and_omit_unrelated_payload(): void
    {
        foreach ([[], ['vendor/package'], ['*']] as $excluded) {
            $record = ['name' => 'vendor/package', 'version' => 'v1.2.3', 'description' => 'description-secret', 'dist' => ['url' => 'credential-secret'], 'extra' => ['laravel' => ['providers' => ['App\\Provider']]]];
            $sources = [
                'composer.json' => json_encode(['scripts' => ['post-autoload-dump' => 'script-secret'], 'extra' => ['laravel' => ['dont-discover' => $excluded]]], JSON_THROW_ON_ERROR),
                'composer.lock' => json_encode(['packages' => [$record]], JSON_THROW_ON_ERROR),
                'vendor/composer/installed.json' => json_encode(['packages' => [$record]], JSON_THROW_ON_ERROR),
                'app/Provider.php' => '<?php namespace App; class Provider extends \\Illuminate\\Support\\ServiceProvider { public function boot() { $this->loadRoutesFrom(__DIR__."/../routes/provided.php"); } }',
                'routes/provided.php' => '<?php \\Illuminate\\Support\\Facades\\Route::get("provided", fn () => 1);',
            ];
            $files = [];
            foreach ($sources as $path => $source) {
                $files[] = new FileContext($path, $source);
            }
            $facts = (new ProjectGraphBuilder(catalog: true))->build($files)->catalogFacts;
            $this->assertStringNotContainsString('-secret', json_encode(array_map(fn ($fact) => $fact->toArray(), $facts), JSON_THROW_ON_ERROR));
            $index = new CatalogIndex($facts);
            $packages = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'composer-package'));
            $this->assertCount(2, $packages);
            $this->assertSame(['locked', 'installed'], array_column(array_column($packages, 'metadata'), 'state'));
            $declared = array_values(array_filter($index->relations, fn ($row) => $row['kind'] === 'declares-package-provider'));
            $this->assertCount(2, $declared);
            $routes = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'route'));
            $registered = array_filter($routes, fn ($row) => in_array('composer.lock', array_column($row['metadata']['registration'], 'path'), true) || in_array('vendor/composer/installed.json', array_column($row['metadata']['registration'], 'path'), true));
            $this->assertSame($excluded === [] ? 2 : 0, count($registered));
        }
    }

    public function test_malformed_and_large_metadata_do_not_enter_php_parser(): void
    {
        $builder = new ProjectGraphBuilder(catalog: true);
        $malformed = $builder->collect(new FileContext('composer.lock', '{"packages": "invalid"}'));
        $this->assertSame('composer_metadata', $malformed->catalog->diagnostics[0]->code);
        $large = $builder->collect(new FileContext('composer.lock', json_encode(['description' => str_repeat('x', 150000), 'packages' => []], JSON_THROW_ON_ERROR)));
        $this->assertSame([], $large->catalog->diagnostics);
        $this->assertSame([], $large->symbols);
    }

    public function test_metadata_limits_are_explicit_and_partial_facts_are_not_cacheable(): void
    {
        $builder = new ProjectGraphBuilder(catalog: true);
        $record = ['name' => 'vendor/package', 'version' => '1.2.3', 'extra' => ['laravel' => ['providers' => array_fill(0, 10001, 'App\\Provider')]]];
        $limited = $builder->collect(new FileContext('composer.lock', json_encode(['packages' => [$record]], JSON_THROW_ON_ERROR)));
        $this->assertContains('catalog_limit', array_map(fn ($row) => $row->code, $limited->catalog->diagnostics));
        $this->assertFalse($limited->catalog->cacheable());
        $oversized = $builder->collect(new FileContext('composer.lock', str_repeat(' ', 4000001)));
        $this->assertSame('source_limit', $oversized->catalog->diagnostics[0]->code);
        $this->assertFalse($oversized->catalog->cacheable());
    }
}
