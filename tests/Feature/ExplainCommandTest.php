<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

class ExplainCommandTest extends TestCase
{
    public function test_it_explains_known_codes_for_agents(): void
    {
        $exitCode = Artisan::call('architecture-kit:explain', [
            'code' => 'E_THIN_CONTROLLER_MODEL_WRITE',
            '--agent' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('explain', $payload['cmd']);
        $this->assertSame('thin-controller', $payload['rule']);
        $this->assertSame('Controller writes through an Eloquent model', $payload['title']);
    }

    public function test_it_reports_unknown_codes_for_agents(): void
    {
        $exitCode = Artisan::call('architecture-kit:explain', [
            'code' => 'E_UNKNOWN',
            '--agent' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('E_UNKNOWN_FINDING_CODE', $payload['m']);
    }

    public function test_it_outputs_agent_schema(): void
    {
        $exitCode = Artisan::call('architecture-kit:explain', [
            '--agent' => true,
            '--schema' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('Architecture Kit explain agent output', $payload['title']);
        $this->assertSame('explain', $payload['oneOf'][0]['properties']['cmd']['const']);
    }

    public function test_without_an_occurrence_the_answer_is_unchanged(): void
    {
        // The existing contract: callers that pass only a code keep what they had.
        $payload = $this->explain(['code' => 'E_THIN_CONTROLLER_MODEL_WRITE', '--agent' => true]);

        $this->assertArrayNotHasKey('occurrence', $payload);
        $this->assertArrayNotHasKey('proposal', $payload);
        $this->assertSame('Move the write workflow into an Action and inject that Action into the controller.', $payload['fix']);
    }

    public function test_an_occurrence_names_the_symbol_at_fault(): void
    {
        // The rule text alone is the same sentence for every violation in the project,
        // so the agent has to work out which element it is about.
        $this->writeController();

        $payload = $this->explain([
            'code' => 'E_THIN_CONTROLLER_MODEL_WRITE',
            '--path' => 'app/Http/Controllers/InvoiceController.php',
            '--line' => 14,
            '--agent' => true,
        ]);

        $this->assertSame('App\\Http\\Controllers\\InvoiceController', $payload['occurrence']['symbol']);
        $this->assertSame('adapter', $payload['occurrence']['role']);
        $this->assertSame(14, $payload['occurrence']['line']);
        $this->assertStringContainsString('App\\Http\\Controllers\\InvoiceController at line 14', $payload['fix']);
    }

    public function test_a_code_with_a_stated_destination_carries_a_proposal(): void
    {
        $this->writeController();

        $payload = $this->explain([
            'code' => 'E_THIN_CONTROLLER_MODEL_WRITE',
            '--path' => 'app/Http/Controllers/InvoiceController.php',
            '--line' => 14,
            '--agent' => true,
        ]);

        $this->assertSame('the model write', $payload['proposal']['move']);
        $this->assertSame('an Action invoked by the controller', $payload['proposal']['to']);
        $this->assertStringContainsString('InvoiceController', $payload['proposal']['summary']);
    }

    public function test_a_code_without_a_stated_destination_carries_no_proposal(): void
    {
        // Guessing where the behaviour belongs would send the agent to the wrong place
        // with confidence, which costs more than saying nothing.
        $this->writeController();

        $payload = $this->explain([
            'code' => 'W_NAMESPACE_CYCLE',
            '--path' => 'app/Http/Controllers/InvoiceController.php',
            '--agent' => true,
        ]);

        $this->assertArrayHasKey('occurrence', $payload);
        $this->assertArrayNotHasKey('proposal', $payload);
    }

    public function test_the_line_decides_which_class_in_a_shared_file_is_named(): void
    {
        // Symbols are stored alphabetically, so taking the first one would describe a
        // finding in Second as if it were about First.
        $this->writePair();

        $first = $this->explain([
            'code' => 'E_THIN_CONTROLLER_MODEL_WRITE',
            '--path' => 'app/Http/Controllers/Pair.php',
            '--line' => 8,
            '--agent' => true,
        ]);
        $second = $this->explain([
            'code' => 'E_THIN_CONTROLLER_MODEL_WRITE',
            '--path' => 'app/Http/Controllers/Pair.php',
            '--line' => 16,
            '--agent' => true,
        ]);

        $this->assertSame('App\\Http\\Controllers\\FirstController', $first['occurrence']['symbol']);
        $this->assertSame('App\\Http\\Controllers\\SecondController', $second['occurrence']['symbol']);
    }

    public function test_a_shared_file_without_a_line_names_no_symbol(): void
    {
        // Nothing to choose on, and a confident wrong name is worse than none.
        $this->writePair();

        $payload = $this->explain([
            'code' => 'E_THIN_CONTROLLER_MODEL_WRITE',
            '--path' => 'app/Http/Controllers/Pair.php',
            '--agent' => true,
        ]);

        $this->assertNull($payload['occurrence']['symbol']);
        $this->assertSame('app/Http/Controllers/Pair.php', $payload['occurrence']['path']);
    }

    private function writePair(): void
    {
        $files = new Filesystem;
        $path = $this->tempPath.'/app/Http/Controllers/Pair.php';
        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Models\Invoice;

final class FirstController
{
    public function store(Invoice $invoice): void
    {
        $invoice->update(['total' => 1]);
    }
}

final class SecondController
{
    public function store(Invoice $invoice): void
    {
        $invoice->update(['total' => 2]);
    }
}
PHP);
    }

    public function test_a_path_without_a_class_still_explains_the_occurrence(): void
    {
        $payload = $this->explain([
            'code' => 'E_ROUTE_MODEL_WRITE',
            '--path' => 'routes/web.php',
            '--line' => 7,
            '--agent' => true,
        ]);

        $this->assertSame('routes/web.php', $payload['occurrence']['path']);
        $this->assertNull($payload['occurrence']['symbol']);
        $this->assertStringContainsString('routes/web.php at line 7', $payload['fix']);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function explain(array $arguments): array
    {
        Artisan::call('architecture-kit:explain', $arguments);

        /** @var array<string, mixed> $payload */
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        return $payload;
    }

    private function writeController(): void
    {
        $files = new Filesystem;
        $path = $this->tempPath.'/app/Http/Controllers/InvoiceController.php';
        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\Request;

final class InvoiceController
{
    public function store(Request $request, Invoice $invoice): void
    {
        $request->validate(['total' => 'required']);

        $invoice->update(['total' => 1]);
    }
}
PHP);
    }
}
