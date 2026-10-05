<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Classification\ClassificationMappings;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

final class ClassificationMappingsTest extends TestCase
{
    public function test_directory_namespace_patterns_and_specific_override(): void
    {
        $m = new ClassificationMappings(['roles' => [
            ['path' => 'app/Billing', 'role' => 'domain'],
            ['path' => 'app/Billing/UseCases', 'role' => 'application', 'kind' => 'action'],
            ['namespace' => 'App\Billing\UseCases', 'role' => 'application'],
            ['pattern' => 'app/*/Readers', 'role' => 'application', 'kind' => 'query'],
        ]]);
        $r = $m->roleMapping('app/Billing/UseCases/Pay.php', 'App\Billing\UseCases\Pay');
        $this->assertSame('application', $r['role']);
        $this->assertSame('action', $r['kind']);
        $this->assertStringContainsString('path:', $r['source']);
        $this->assertStringContainsString('namespace:', $r['source']);
        $this->assertSame('query', $m->roleMapping('app/Shipping/Readers/List.php', 'App\Shipping\Readers\ListQuery')['kind']);
        $this->assertSame([], $m->roleMapping('app/Other/Pay.php', 'App\Other\Pay'));
    }

    public function test_conflicting_namespace_and_patterns_do_not_silently_choose(): void
    {
        foreach ([
            [['path' => 'app/Billing', 'role' => 'application'], ['namespace' => 'App\Billing', 'role' => 'domain']],
            [['pattern' => 'app/*/UseCases', 'kind' => 'action'], ['pattern' => 'app/**', 'kind' => 'query']],
        ] as $roles) {
            $m = new ClassificationMappings(['roles' => $roles]);
            try {
                $m->roleMapping('app/Billing/UseCases/Pay.php', 'App\Billing\UseCases\Pay');
                $this->fail('Conflicting selectors must fail.');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_module_owner_inferred_and_explicit_parents_shared_models(): void
    {
        $m = new ClassificationMappings(['modules' => [
            ['name' => 'Billing', 'path' => 'app/Billing'],
            ['name' => 'Refunds', 'path' => 'app/Billing/Refunds'],
            ['name' => 'Billing', 'namespace' => 'App\Billing'],
            ['name' => 'Refunds', 'namespace' => 'App\Billing\Refunds'],
        ]]);
        $r = $m->module('app/Billing/Refunds/Pay.php', 'App\Billing\Refunds\Pay');
        $this->assertSame('Refunds', $r['module']);
        $this->assertSame(['Billing'], $r['parents']);
        $this->assertNull($m->module('app/Models/User.php', 'App\Models\User')['module']);
        $this->assertSame('Refunds', $m->module('app/Billing/Refunds/Models/Refund.php', 'App\Billing\Refunds\Models\Refund')['module']);
        $other = new ClassificationMappings(['modules' => [['name' => 'Billing', 'path' => 'app/Billing'], ['name' => 'Refunds', 'path' => 'app/Refunds', 'parent' => 'Billing']]]);
        $this->assertSame(['Billing'], $other->module('app/Refunds/Pay.php', 'App\Refunds\Pay')['parents']);
    }

    public function test_ambiguous_module_ownership_is_an_error(): void
    {
        $m = new ClassificationMappings(['modules' => [['name' => 'Billing', 'path' => 'app/Billing'], ['name' => 'Orders', 'namespace' => 'App\Billing']]]);
        $this->expectException(InvalidArgumentException::class);
        $m->module('app/Billing/Pay.php', 'App\Billing\Pay');
    }

    public function test_validation_unknown_levels_and_fingerprints(): void
    {
        $bad = [
            ['roles' => [['path' => '../app', 'role' => 'domain']]],
            ['roles' => [['pattern' => 'app/Bill*/UseCases', 'kind' => 'action']]],
            ['roles' => [['path' => 'app/Billing', 'role' => 'business']]],
            ['roles' => [['path' => 'app/Billing', 'kind' => 'class']]],
            ['roles' => [['namespace' => 'App\\\\Bad', 'role' => 'domain']]],
            ['modules' => [['name' => 'A', 'path' => 'app/A', 'parent' => 'Missing']]],
            ['modules' => [['name' => 'A', 'path' => 'app/A', 'parent' => 'B'], ['name' => 'B', 'path' => 'app/B', 'parent' => 'A']]],
            ['unknown_role' => 'fatal'],
        ];
        foreach ($bad as $config) {
            try {
                new ClassificationMappings($config);
                $this->fail('Bad configuration must fail: '.json_encode($config));
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame('off', (new ClassificationMappings)->unknownLevel);
        $this->assertSame('warn', (new ClassificationMappings(['unknown_role' => 'warn']))->unknownLevel);
        $this->assertNotSame((new ClassificationMappings)->fingerprint(), (new ClassificationMappings(['unknown_role' => 'error']))->fingerprint());
    }

    public function test_duplicate_declaration_configuration_and_multiple_returns_fail_explicitly(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/config');
        foreach (['return ["audit" => ["classification" => []], "audit" => []];', 'return ["audit" => ["classification" => [], "classification" => []]];', 'return ["audit" => ["classification" => []]]; return [];'] as $source) {
            $files->put($this->tempPath.'/config/architectures.php', '<?php '.$source);
            $rejected = false;
            try {
                ClassificationMappings::load($files, $this->tempPath);
            } catch (InvalidArgumentException) {
                $rejected = true;
            }
            $this->assertTrue($rejected);
        }
    }

    public function test_static_loader_never_executes_configuration_code(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/config');
        $files->put($this->tempPath.'/config/architectures.php', '<?php return ["enabled"=>[Unknown::Pattern], "audit"=>["classification"=>["roles"=>[["path"=>"app/Billing/UseCases", "kind"=>"action"]]]]];');
        $this->assertSame('action', ClassificationMappings::load($files, $this->tempPath)->roleMapping('app/Billing/UseCases/Pay.php', 'App\Billing\UseCases\Pay')['kind']);
        $files->put($this->tempPath.'/config/architectures.php', '<?php file_put_contents(__DIR__."/executed", "bad"); return ["audit"=>["classification"=>[]]];');
        try {
            ClassificationMappings::load($files, $this->tempPath);
            $this->fail('Executable declarations must fail.');
        } catch (InvalidArgumentException) {
            $this->assertFileDoesNotExist($this->tempPath.'/config/executed');
        }
    }
}
