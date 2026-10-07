<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\AuditScope;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphLoader;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogSettings;
use GracjanKubicki\ArchitectureKit\Context\GraphSource;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class CatalogSourceTest extends TestCase
{
    public function test_graph_source_composes_saloon_urls_cold_and_warm_without_running_selectors(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/composer.lock', '{"packages":[{"name":"saloonphp/saloon","version":"4.0.0"}]}');
        $files->put($this->tempPath.'/app/Billing.php', '<?php namespace App; class Billing extends \\Saloon\\Http\\Connector { public bool $allowBaseUrlOverride = true; public function resolveBaseUrl() { return "https://billing.test"; } } throw new \\RuntimeException("never execute source");');
        $files->put($this->tempPath.'/app/Invoice.php', '<?php namespace App; class Invoice extends \\Saloon\\Http\\Request { public function resolveEndpoint() { return "https://uploads.test/invoices?token=credential-secret"; } }');
        $files->put($this->tempPath.'/app/Action.php', '<?php namespace App; class Action { public function run(Billing $billing) { $billing->send(new Invoice); } }');
        $parses = [];
        $source = new GraphSource($files, $this->tempPath, function (string $path) use (&$parses): void {
            $parses[] = $path;
        });
        foreach ([false, true] as $warm) {
            $parses = [];
            $result = $source->load();
            $index = $result['index'];
            $sends = array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'uses-external-service'));
            $this->assertCount(1, $sends);
            $this->assertSame('https://uploads.test/invoices', $index->elements[$sends[0]['to']]['name']);
            $this->assertTrue($sends[0]['metadata']['absolute_endpoint_override']);
            $this->assertFalse($result['changed']);
            foreach (['app/Billing.php', 'app/Invoice.php', 'app/Action.php'] as $path) {
                $this->assertSame($warm ? 0 : 1, count(array_filter($parses, fn ($parsed) => $parsed === $path)));
            }
            $this->assertStringNotContainsString('credential-secret', json_encode([$index->elements, $index->relations], JSON_THROW_ON_ERROR));
        }
    }

    public function test_graph_source_resolves_cached_cross_file_aliases_without_another_ast_parse(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->put($this->tempPath.'/app/Names.php', <<<'SOURCE'
<?php namespace App;
trait Names { public function tableName() { return 'ExplicitRows'; } protected function castMap() { return ['settings' => 'array']; } }
throw new \RuntimeException('execution-sentinel');
SOURCE);
        $files->put($this->tempPath.'/app/Order.php', '<?php namespace App; class Order extends \\Illuminate\\Database\\Eloquent\\Model { use Names { tableName as getTable; castMap as protected casts; } } class Action { public function run() { Order::get(); } }');
        $parses = [];
        $source = new GraphSource($files, $this->tempPath, function (string $path) use (&$parses): void {
            $parses[] = $path;
        });
        foreach ([false, true] as $warm) {
            $parses = [];
            $result = $source->load();
            $index = $result['index'];
            $this->assertFalse($result['changed']);
            $reads = array_values(array_filter($index->relations, fn ($edge) => $edge['kind'] === 'reads'));
            $this->assertCount(1, $reads);
            $this->assertSame('ExplicitRows', $index->elements[$reads[0]['to']]['name']);
            $this->assertTrue($index->elements[$index->names['app\\order'][0]]['metadata']['casts_resolved']);
            foreach (['app/Names.php', 'app/Order.php'] as $path) {
                $this->assertSame($warm ? 0 : 1, count(array_filter($parses, fn ($parsed) => $parsed === $path)));
            }
        }
    }

    public function test_composer_metadata_symlinks_are_excluded_at_every_path_segment(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/private');
        $files->put($this->tempPath.'/private/metadata.json', '{"packages":[{"name":"private/sentinel","version":"1.0.0"}]}');
        $loader = new ProjectGraphLoader($files, $this->tempPath, sourceOnly: true, catalog: true);
        symlink($this->tempPath.'/private/metadata.json', $this->tempPath.'/composer.lock');
        try {
            $this->assertArrayNotHasKey('composer.lock', $loader->plan()->files);
        } finally {
            unlink($this->tempPath.'/composer.lock');
        }
        $files->ensureDirectoryExists($this->tempPath.'/private/composer');
        $files->put($this->tempPath.'/private/composer/installed.json', '{"packages":[]}');
        symlink($this->tempPath.'/private', $this->tempPath.'/vendor');
        try {
            $this->assertArrayNotHasKey('vendor/composer/installed.json', $loader->plan()->files);
        } finally {
            unlink($this->tempPath.'/vendor');
        }
        $files->ensureDirectoryExists($this->tempPath.'/vendor');
        symlink($this->tempPath.'/private/composer', $this->tempPath.'/vendor/composer');
        try {
            $this->assertArrayNotHasKey('vendor/composer/installed.json', $loader->plan()->files);
        } finally {
            unlink($this->tempPath.'/vendor/composer');
        }
        $files->ensureDirectoryExists($this->tempPath.'/vendor/composer');
        symlink($this->tempPath.'/private/metadata.json', $this->tempPath.'/vendor/composer/installed.json');
        try {
            $this->assertArrayNotHasKey('vendor/composer/installed.json', $loader->plan()->files);
        } finally {
            unlink($this->tempPath.'/vendor/composer/installed.json');
        }
    }

    public function test_source_roots_include_classless_files_and_exclusions_do_not_change_audit_test_rules(): void
    {
        $files = new Filesystem;
        foreach (['app', 'routes', 'bootstrap', 'config', 'database', 'resources/views', 'tests', 'src/Billing'] as $root) {
            $files->ensureDirectoryExists($this->tempPath.'/'.$root);
        }
        $files->put($this->tempPath.'/config/architectures.php', '<?php return ["graph" => ["paths" => ["src/Billing"], "exclude" => ["tests/Excluded.php"]]];');
        foreach (['app/functions.php', 'routes/web.php', 'bootstrap/providers.php', 'database/seed.php', 'tests/Excluded.php', 'tests/Kept.php', 'src/Billing/functions.php'] as $path) {
            $files->put($this->tempPath.'/'.$path, '<?php throw new \\RuntimeException("source only"); function example() {}');
        }
        $files->put($this->tempPath.'/resources/views/example.blade.php', '<h1>Example</h1>');
        $files->put($this->tempPath.'/.env', 'SECRET=never read');
        $settings = CatalogSettings::load($files, $this->tempPath);
        $loader = new ProjectGraphLoader($files, $this->tempPath, $settings->scope, sourceOnly: true, catalog: true);
        $plan = $loader->plan($settings->exclude);
        $this->assertArrayNotHasKey('tests/Excluded.php', $plan->files);
        $this->assertArrayHasKey('tests/Kept.php', $plan->files);
        $this->assertArrayHasKey('resources/views/example.blade.php', $plan->files);
        $this->assertArrayHasKey('src/Billing/functions.php', $plan->files);
        $this->assertArrayNotHasKey('.env', $plan->files);
        $graph = $loader->build($plan);
        $this->assertCount(count($plan->files), $graph->catalogFacts);
        $legacy = new ProjectGraphLoader($files, $this->tempPath, new AuditScope(['app', 'tests']));
        $this->assertArrayHasKey('tests/Excluded.php', $legacy->plan(['tests/Excluded.php'])->files);
    }

    public function test_source_loader_skips_a_symlink_root_before_listing_it(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/private');
        $files->put($this->tempPath.'/private/Secret.php', '<?php throw new \\RuntimeException("must not read linked source");');
        symlink($this->tempPath.'/private', $this->tempPath.'/routes');
        $loader = new ProjectGraphLoader($files, $this->tempPath, new AuditScope(['app', 'routes']), sourceOnly: true, catalog: true);
        $this->assertSame(['composer.json'], array_keys($loader->plan()->files));
        unlink($this->tempPath.'/routes');
    }
}
