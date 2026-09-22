<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAuditResult;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;

final class Gh17FrameworkSemanticsTest extends TestCase
{
    #[DataProvider('effects')]
    public function test_framework_and_project_paths_keep_real_effects_visible(string $body, string $traceFragment): void
    {
        $result = $this->analyse($body);

        $this->assertCount(1, $result->suggestions, implode("\n", array_column($result->notices, 'message')));
        $this->assertSame('S_MOVE_WRITE_TO_ACTION', $result->suggestions[0]->code);
        $this->assertStringContainsString($traceFragment, implode(' -> ', $result->suggestions[0]->trace).' '.$result->suggestions[0]->reason);
        $this->assertSame([], $result->notices);
    }

    public static function effects(): iterable
    {
        yield 'map callback' => [
            'return Invoice::query()->get()->map(function ($item) { $item->save(); return $item; });',
            '{callback}',
        ];
        yield 'values map callback' => [
            'return Invoice::query()->get()->values()->map(fn ($value) => $value->save());',
            '{callback}',
        ];
        yield 'firstWhere nullable element' => [
            'return Invoice::query()->get()->firstWhere("id", 1)?->save();',
            'Invoice::save',
        ];
        yield 'when default callback' => [
            'return Invoice::query()->when($flag, fn ($query) => $query->where("id", 1), fn ($query) => $query->update([]));',
            '{callback}',
        ];
        yield 'when positional names' => [
            'return Invoice::query()->when($flag, fn ($builder, $value) => $builder->where("id", $value), fn ($fallbackBuilder, $fallbackValue) => $fallbackBuilder->update([]));',
            '{callback}',
        ];
        yield 'query each model element' => [
            'return Invoice::query()->each(function ($item, $key) { $item->save(); });',
            '{callback}',
        ];
        yield 'response header argument' => [
            'return response()->header("X-Total", Invoice::query()->update([]));',
            'Invoice::update',
        ];
        yield 'filter_var argument' => [
            'return filter_var(Invoice::query()->update([]), FILTER_VALIDATE_BOOLEAN);',
            'Invoice::update',
        ];
        yield 'throw_if exception constructor' => [
            'throw_if($flag, \\App\\Data\\FailingException::class);',
            'FailingException::__construct',
        ];
        yield 'custom firstWhere override' => [
            'return (new \\App\\Support\\LocalCollection)->firstWhere("id", 1);',
            'LocalCollection::firstWhere',
        ];
        yield 'custom header override' => [
            'return (new \\App\\Support\\LocalResponse)->header("X-Test", "1");',
            'LocalResponse::header',
        ];
        yield 'model accessor' => [
            'return $invoice->total;',
            'Invoice::getTotalAttribute',
        ];
    }

    #[DataProvider('cleanCalls')]
    public function test_supported_reads_and_callbacks_do_not_create_noise(string $body): void
    {
        $result = $this->analyse($body);

        $this->assertSame([], $result->suggestions);
        $this->assertSame([], $result->notices);
        $this->assertSame('complete', $result->analysisStatus);
    }

    public static function cleanCalls(): iterable
    {
        yield 'collection map read' => ['return Invoice::query()->get()->map(fn ($item) => $item->getKey())->implode(",");'];
        yield 'values map read' => ['return Invoice::query()->get()->values()->map(fn ($value) => $value->getKey())->implode(",");'];
        yield 'keys map read' => ['return Invoice::query()->get()->keys()->map(fn ($key) => $key)->implode(",");'];
        yield 'pluck first nullable read' => ['return Invoice::query()->get()->pluck("id")->first();'];
        yield 'model first nullable read' => ['return Invoice::query()->get()->first()?->getKey();'];
        yield 'response header read' => ['return response()->header("X-Read", "yes");'];
        yield 'plain filter_var' => ['return filter_var("yes", FILTER_VALIDATE_BOOLEAN);'];
        yield 'plain serialization' => ['return serialize(["connection" => "plain"]);'];
        yield 'collection each read' => ['return Invoice::query()->get()->each(fn ($item) => $item->getKey());'];
    }

    public function test_dynamic_collection_callback_is_an_analysis_notice(): void
    {
        $result = $this->analyse('return Invoice::query()->get()->map($callback);');

        $this->assertSame([], $result->suggestions);
        $this->assertCount(1, $result->notices);
        $this->assertSame('A_CALL_UNRESOLVED', $result->notices[0]->code);
        $this->assertSame('incomplete', $result->analysisStatus);
    }

    private function analyse(string $body): ApplicationAuditResult
    {
        $this->write('app/Models/Invoice.php', <<<'PHP'
<?php
namespace App\Models;
final class Invoice extends \Illuminate\Database\Eloquent\Model {
    public function getTotalAttribute(): mixed { $this->save(); return $this->getAttribute("total"); }
}
PHP);
        $this->write('app/Support/LocalCollection.php', '<?php namespace App\Support; use App\Models\Invoice; final class LocalCollection { public function firstWhere(...$args) { Invoice::create([]); } }');
        $this->write('app/Support/LocalResponse.php', '<?php namespace App\Support; use App\Models\Invoice; final class LocalResponse { public function header(...$args) { Invoice::create([]); } }');
        $this->write('app/Data/FailingException.php', '<?php namespace App\Data; use App\Models\Invoice; final class FailingException extends \\RuntimeException { public function __construct(...$args) { Invoice::create([]); } }');
        $this->write('app/Http/Controllers/FrameworkController.php', <<<PHP
<?php
namespace App\Http\Controllers;
use App\Models\Invoice;
final class FrameworkController {
    public function show(Invoice \$invoice, bool \$flag = false) { {$body} }
}
PHP);

        return (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [],
            changedOnly: false,
            routes: new RouteMap(['app\http\controllers\frameworkcontroller::show' => ['GET', 'HEAD']]),
        );
    }

    private function write(string $path, string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $source);
    }
}
