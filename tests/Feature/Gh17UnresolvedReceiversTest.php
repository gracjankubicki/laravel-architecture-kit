<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;

final class Gh17UnresolvedReceiversTest extends TestCase
{
    public function test_new_resource_reaches_only_its_serialized_transformation(): void
    {
        $this->base();
        $this->write('app/Http/Resources/InvoiceResource.php', <<<'PHP'
<?php
namespace App\Http\Resources;
use App\Models\Invoice;
final class InvoiceResource extends \Illuminate\Http\Resources\Json\JsonResource {
    public function toArray($request): array { Invoice::query()->update([]); return []; }
    public function unused(): void { Invoice::query()->delete(); }
}
PHP);
        $this->controller('public function show(Invoice $invoice) { return new \App\Http\Resources\InvoiceResource($invoice); }');

        $result = $this->analyse();

        $this->assertCount(1, $result->suggestions);
        $this->assertStringContainsString('InvoiceResource::toArray', implode(' -> ', $result->suggestions[0]->trace));
        $this->assertStringNotContainsString('unused', implode(' -> ', $result->suggestions[0]->trace));
        $this->assertSame([], $result->notices);
    }

    public function test_scalar_and_array_serialization_use_php_contracts_without_noise(): void
    {
        $this->base();
        $this->controller('public function show() { return [serialize(1), serialize([]), unserialize("i:1;"), unserialize("a:0:{}")]; }');

        $result = $this->analyse();

        $this->assertSame([], $result->suggestions);
        $this->assertSame([], $result->notices);
        $this->assertSame('complete', $result->analysisStatus);
    }

    public function test_known_serialization_hook_without_effect_is_clean(): void
    {
        $this->base();
        $this->write('app/Data/SerializationProbe.php', <<<'PHP'
<?php
namespace App\Data;
final class SerializationProbe {
    public function __serialize(): array { return []; }
    public function __sleep(): array { return []; }
}
PHP);
        $this->controller('public function show() { return serialize(new \App\Data\SerializationProbe); }');

        $result = $this->analyse();

        $this->assertSame([], $result->suggestions);
        $this->assertSame([], $result->notices);
        $this->assertSame('complete', $result->analysisStatus);
    }

    public function test_serialization_hook_write_remains_visible(): void
    {
        $this->base();
        $this->write('app/Data/SerializationProbe.php', <<<'PHP'
<?php
namespace App\Data;
use App\Models\Invoice;
final class SerializationProbe {
    public function __serialize(): array { Invoice::query()->update([]); return []; }
}
PHP);
        $this->controller('public function show() { return serialize(new \App\Data\SerializationProbe); }');

        $result = $this->analyse();

        $this->assertCount(1, $result->suggestions);
        $this->assertStringContainsString('SerializationProbe::__serialize', implode(' -> ', $result->suggestions[0]->trace));
        $this->assertSame([], $result->notices);
    }

    public function test_unserialize_resolves_known_class_and_analyses_wakeup_hook(): void
    {
        $this->base();
        $this->write('app/Data/WakeupProbe.php', <<<'PHP'
<?php
namespace App\Data;
use App\Models\Invoice;
final class WakeupProbe {
    public function __unserialize(array $data): void { Invoice::query()->update([]); }
}
PHP);
        $payload = sprintf('O:%d:"%s":0:{}', strlen('App\Data\WakeupProbe'), 'App\Data\WakeupProbe');
        $literal = var_export($payload, true);
        $this->controller('public function show() { return unserialize('.$literal.'); }');

        $result = $this->analyse();

        $this->assertCount(1, $result->suggestions);
        $this->assertStringContainsString('WakeupProbe::__unserialize', implode(' -> ', $result->suggestions[0]->trace));
        $this->assertSame([], $result->notices);
    }

    public function test_serializable_hook_write_remains_visible(): void
    {
        $this->base();
        $this->write('app/Data/SerializableProbe.php', <<<'PHP'
<?php
namespace App\Data;
use App\Models\Invoice;
final class SerializableProbe implements \Serializable {
    public function serialize(): string { Invoice::query()->update([]); return ''; }
    public function unserialize(string $data): void {}
}
PHP);
        $this->controller('public function show() { return serialize(new \App\Data\SerializableProbe); }');

        $result = $this->analyse();

        $this->assertCount(1, $result->suggestions);
        $this->assertStringContainsString('SerializableProbe::serialize', implode(' -> ', $result->suggestions[0]->trace));
        $this->assertSame([], $result->notices);
    }

    #[DataProvider('inheritedSerializableHooks')]
    public function test_inherited_and_interface_serializable_hooks_remain_visible(string $class): void
    {
        $this->base();
        $this->write('app/Data/SerializationContracts.php', <<<'PHP'
<?php
namespace App\Data;
use App\Models\Invoice;
interface SerializationContract extends \Serializable {}
class BaseSerializationProbe implements \Serializable {
    public function serialize(): string { Invoice::query()->update([]); return ''; }
    public function unserialize(string $data): void {}
}
final class InheritedSerializationProbe extends BaseSerializationProbe {}
final class InterfaceSerializationProbe implements SerializationContract {
    public function serialize(): string { Invoice::query()->update([]); return ''; }
    public function unserialize(string $data): void {}
}
PHP);
        $this->controller('public function show() { return serialize(new \\App\\Data\\'.$class.'); }');

        $result = $this->analyse();

        $this->assertCount(1, $result->suggestions);
        $this->assertStringContainsString($class.'::serialize', implode(' -> ', $result->suggestions[0]->trace));
        $this->assertSame([], $result->notices);
    }

    public static function inheritedSerializableHooks(): iterable
    {
        yield 'base class contract' => ['InheritedSerializationProbe'];
        yield 'intermediate interface contract' => ['InterfaceSerializationProbe'];
    }

    public function test_syntactically_valid_custom_serialization_is_incomplete(): void
    {
        $this->base();
        $payload = serialize(new \ArrayObject);
        $this->controller('public function show() { return unserialize('.var_export($payload, true).'); }');

        $result = $this->analyse();

        $this->assertSame([], $result->suggestions);
        $this->assertCount(1, $result->notices);
        $this->assertSame('A_CALL_UNRESOLVED', $result->notices[0]->code);
        $this->assertSame('incomplete', $result->analysisStatus);
    }

    #[DataProvider('malformedSerialization')]
    public function test_malformed_serialization_is_always_incomplete(string $payload): void
    {
        $this->base();
        $this->controller('public function show() { return unserialize('.var_export($payload, true).'); }');

        $result = $this->analyse();

        $this->assertSame([], $result->suggestions);
        $this->assertCount(1, $result->notices);
        $this->assertSame('A_CALL_UNRESOLVED', $result->notices[0]->code);
        $this->assertSame('incomplete', $result->analysisStatus);
    }

    public static function malformedSerialization(): iterable
    {
        yield 'malformed integer' => ['i:not-an-int;'];
        yield 'malformed array' => ['a:not-valid'];
        yield 'wrong object class length' => ['O:999:"App\Data\SerializationProbe":0:{}'];
        yield 'wrong string length' => ['s:3:"ab";'];
    }

    public function test_unknown_serialization_payload_is_an_explicit_notice(): void
    {
        $this->base();
        $this->controller('public function show() { return unserialize("not-a-php-serialization-payload"); }');

        $result = $this->analyse();

        $this->assertSame([], $result->suggestions);
        $this->assertCount(1, $result->notices);
        $this->assertSame('A_CALL_UNRESOLVED', $result->notices[0]->code);
        $this->assertSame('incomplete', $result->analysisStatus);
    }

    #[DataProvider('unsupportedConnectionSerialization')]
    public function test_connection_serialization_methods_are_not_a_fake_framework_contract(string $body): void
    {
        $this->base();
        $this->controller($body);

        $result = $this->analyse();

        $this->assertSame([], $result->suggestions);
        $this->assertNotEmpty($result->notices);
        $this->assertSame('incomplete', $result->analysisStatus);
    }

    public static function unsupportedConnectionSerialization(): iterable
    {
        yield 'serialize method' => ['public function show(\Illuminate\Database\Connection $connection) { return $connection->serialize(); }'];
        yield 'unserialize method' => ['public function show(\Illuminate\Database\Connection $connection, string $payload) { return $connection->unserialize($payload); }'];
        yield 'serialize connection object' => ['public function show(\Illuminate\Database\Connection $connection) { return serialize($connection); }'];
    }

    public function test_effect_before_serialization_remains_visible(): void
    {
        $this->base();
        $this->controller('public function show(\Illuminate\Database\Connection $connection) { return serialize($connection->update("invoices", ["total" => 1], ["id" => 1])); }');

        $result = $this->analyse();

        $this->assertCount(1, $result->suggestions);
        $this->assertStringContainsString('Connection::update', $result->suggestions[0]->reason);
        $this->assertSame([], $result->notices);
    }

    public function test_project_owned_serialization_override_keeps_its_write(): void
    {
        $this->base();
        $this->write('app/Data/Connection.php', '<?php namespace App\Data; use App\Models\Invoice; final class Connection { public function serialize() { Invoice::create([]); return "ok"; } }');
        $this->controller('public function show(\App\Data\Connection $connection) { return serialize($connection->serialize()); }');

        $result = $this->analyse();

        $this->assertCount(1, $result->suggestions);
        $this->assertStringContainsString('Connection::serialize', implode(' -> ', $result->suggestions[0]->trace));
        $this->assertSame([], $result->notices);
    }

    #[DataProvider('effectChains')]
    public function test_receiver_fixes_do_not_hide_real_update_or_create_effects(string $body): void
    {
        $this->base();
        $this->controller($body);

        $result = $this->analyse();

        $this->assertCount(1, $result->suggestions);
        $this->assertSame([], $result->notices);
    }

    public static function effectChains(): iterable
    {
        yield 'updateOrCreate' => ['public function show(\App\Models\Invoice $invoice) { return \App\Models\Invoice::query()->where("id", $invoice->getKey())->updateOrCreate([], ["total" => 1]); }'];
        yield 'resource callback' => ['public function show(\App\Models\Invoice $invoice) { return new \App\Http\Resources\InvoiceResource($invoice); }'];
    }

    private function base(): void
    {
        $this->write('app/Models/Invoice.php', '<?php namespace App\Models; final class Invoice extends \Illuminate\Database\Eloquent\Model {}');
        $this->write('app/Http/Resources/InvoiceResource.php', <<<'PHP'
<?php
namespace App\Http\Resources;
use App\Models\Invoice;
final class InvoiceResource extends \Illuminate\Http\Resources\Json\JsonResource {
    public function toArray($request): array { Invoice::query()->update([]); return []; }
}
PHP);
    }

    private function analyse(): ApplicationAuditResult
    {
        return (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [],
            changedOnly: false,
            routes: new RouteMap(['app\http\controllers\frameworkcontroller::show' => ['GET', 'HEAD']]),
        );
    }

    private function controller(string $method): void
    {
        $this->write('app/Http/Controllers/FrameworkController.php', '<?php namespace App\Http\Controllers; use App\Models\Invoice; final class FrameworkController { '.$method.' }');
    }

    private function write(string $path, string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $source);
    }
}
