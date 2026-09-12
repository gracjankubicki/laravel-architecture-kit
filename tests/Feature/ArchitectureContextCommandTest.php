<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\ArchitectureContext;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

final class ArchitectureContextCommandTest extends TestCase
{
    public function test_cli_resolves_fqcn_and_path_with_deterministic_bounded_agent_output(): void
    {
        $this->writeFixture();

        $exit = Artisan::call('architecture-kit:context', [
            'subject' => 'App\Actions\FetchDocument',
            '--agent' => true,
            '--limit' => 1,
        ]);
        $fqcn = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('architecture-context', $fqcn['cmd']);
        $this->assertSame('App\Actions\FetchDocument', $fqcn['subject']['name']);
        $this->assertSame('application', $fqcn['subject']['role']);
        $this->assertCount(1, $fqcn['dependencies']);
        $this->assertSame('App\Documents\Ports\DocumentGateway', $fqcn['dependencies'][0]['symbol']);
        $this->assertCount(1, $fqcn['dependents']);
        $this->assertSame('App\Http\Controllers\DocumentController', $fqcn['dependents'][0]['symbol']);
        $this->assertTrue($fqcn['trunc']);
        $this->assertContains('app/Actions/FetchDocument.php', $fqcn['inspect']);

        $exit = Artisan::call('architecture-kit:context', [
            'subject' => 'app/Actions/FetchDocument.php',
            '--agent' => true,
        ]);
        $path = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame($fqcn['subject'], $path['subject']);
        $this->assertSame($fqcn['dependencies'], $path['dependencies']);
    }

    public function test_cli_fails_closed_for_missing_and_ambiguous_subjects(): void
    {
        $this->writeConfig([Architecture::Actions]);
        $this->writeFile('app/Actions/Multiple.php', <<<'PHP'
<?php

namespace App\Actions;

final class FirstAction
{
}

final class SecondAction
{
}
PHP);

        $exit = Artisan::call('architecture-kit:context', [
            'subject' => 'app/Actions/Multiple.php',
            '--agent' => true,
        ]);
        $ambiguous = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('E_CONTEXT_SUBJECT_AMBIGUOUS', $ambiguous['m']);

        $exit = Artisan::call('architecture-kit:context', [
            'subject' => 'App\Actions\Missing',
            '--agent' => true,
        ]);
        $missing = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('E_CONTEXT_SUBJECT_NOT_FOUND', $missing['m']);
    }

    public function test_cli_schema_and_mcp_use_the_same_context_contract(): void
    {
        $this->writeFixture();

        $exit = Artisan::call('architecture-kit:context', ['--schema' => true]);
        $schema = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('Architecture Kit architecture context agent output', $schema['title']);
        $this->assertSame('architecture-context', $schema['oneOf'][0]['properties']['cmd']['const']);

        Artisan::call('architecture-kit:context', [
            'subject' => 'App\Actions\FetchDocument',
            '--agent' => true,
        ]);
        $cli = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        ArchitectureKitServer::tool(ArchitectureContext::class, [
            'subject' => 'App\Actions\FetchDocument',
        ])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('cmd', $cli['cmd'])
                ->where('subject', $cli['subject'])
                ->where('dependencies', $cli['dependencies'])
                ->where('dependents', $cli['dependents'])
                ->where('violations', $cli['violations'])
                ->where('inspect', $cli['inspect'])
                ->where('next', $cli['next'])
                ->etc()
            );
    }

    public function test_context_reports_truncation_when_only_the_combined_inspect_paths_exceed_the_limit(): void
    {
        $this->writeConfig([Architecture::Actions]);
        $this->writeFile('app/Actions/SubjectAction.php', <<<'PHP'
<?php

namespace App\Actions;

use App\Data\DependencyData;

final class SubjectAction
{
    public function __construct(private DependencyData $data)
    {
    }
}
PHP);
        $this->writeFile('app/Data/DependencyData.php', <<<'PHP'
<?php

namespace App\Data;

final readonly class DependencyData
{
}
PHP);
        $this->writeFile('app/Http/Controllers/SubjectController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Actions\SubjectAction;

final class SubjectController
{
    public function __construct(private SubjectAction $action)
    {
    }
}
PHP);

        $exit = Artisan::call('architecture-kit:context', [
            'subject' => 'App\Actions\SubjectAction',
            '--agent' => true,
            '--limit' => 2,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertCount(1, $payload['dependencies']);
        $this->assertCount(1, $payload['dependents']);
        $this->assertCount(2, $payload['inspect']);
        $this->assertTrue($payload['trunc']);
    }

    public function test_zero_limit_reports_truncation_when_the_subject_inspect_path_is_hidden(): void
    {
        $this->writeConfig([Architecture::Actions]);
        $this->writeFile('app/Actions/SubjectAction.php', <<<'PHP'
<?php

namespace App\Actions;

final class SubjectAction
{
}
PHP);

        $exit = Artisan::call('architecture-kit:context', [
            'subject' => 'App\Actions\SubjectAction',
            '--agent' => true,
            '--limit' => 0,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame([], $payload['inspect']);
        $this->assertTrue($payload['trunc']);
    }

    private function writeFixture(): void
    {
        $this->writeConfig([Architecture::Actions, Architecture::PortsAndAdapters]);
        $this->writeFile('app/Documents/Ports/DocumentGateway.php', <<<'PHP'
<?php

namespace App\Documents\Ports;

interface DocumentGateway
{
    public function fetch(): string;
}
PHP);
        $this->writeFile('app/Actions/FetchDocument.php', <<<'PHP'
<?php

namespace App\Actions;

use App\Documents\Ports\DocumentGateway;

final class FetchDocument
{
    public function __construct(private DocumentGateway $gateway)
    {
    }

    public function handle(): string
    {
        return $this->gateway->fetch();
    }
}
PHP);
        $this->writeFile('app/Http/Controllers/DocumentController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Actions\FetchDocument;

final class DocumentController
{
    public function __construct(private FetchDocument $action)
    {
    }
}
PHP);
        $this->writeFile('app/Http/Controllers/OtherController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Actions\FetchDocument;

final class OtherController
{
    public function __construct(private FetchDocument $action)
    {
    }
}
PHP);
    }

    public function test_truncation_keeps_what_breaks_first_not_what_sorts_first(): void
    {
        // The whole point of ranking: `AaaReader` sorts first by name but only mentions
        // the subject in a signature, while `ZzzHandler` extends it and stops loading the
        // moment the subject changes. Alphabetical order used to drop the second one.
        $this->writeConfig([Architecture::Actions]);
        $this->writeFile('app/Actions/BaseAction.php', <<<'PHP'
<?php

namespace App\Actions;

abstract class BaseAction
{
    abstract public function handle(): void;
}
PHP);
        $this->writeFile('app/Actions/AaaReader.php', <<<'PHP'
<?php

namespace App\Actions;

final class AaaReader
{
    public function read(BaseAction $action): void
    {
    }
}
PHP);
        $this->writeFile('app/Actions/ZzzHandler.php', <<<'PHP'
<?php

namespace App\Actions;

final class ZzzHandler extends BaseAction
{
    public function handle(): void
    {
    }
}
PHP);

        Artisan::call('architecture-kit:context', [
            'subject' => 'App\Actions\BaseAction',
            '--agent' => true,
            '--limit' => 1,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(1, $payload['dependents']);
        $this->assertSame('App\Actions\ZzzHandler', $payload['dependents'][0]['symbol']);
        $this->assertSame('breaking', $payload['dependents'][0]['impact']);
        $this->assertTrue($payload['trunc']);
    }

    public function test_context_reports_the_tests_that_cover_the_subject(): void
    {
        $this->writeFixture();
        $this->writeFile('tests/Feature/FetchDocumentTest.php', <<<'PHP'
<?php

namespace Tests\Feature;

use App\Actions\FetchDocument;
use PHPUnit\Framework\TestCase;

final class FetchDocumentTest extends TestCase
{
    public function test_it_fetches(): void
    {
        $this->assertTrue(class_exists(FetchDocument::class));
    }
}
PHP);

        Artisan::call('architecture-kit:context', [
            'subject' => 'App\Actions\FetchDocument',
            '--agent' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([[
            'path' => 'tests/Feature/FetchDocumentTest.php',
            'coverage' => 'direct',
            'via' => null,
        ]], $payload['tests']);
        $this->assertContains('run_tests:tests/Feature/FetchDocumentTest.php', $payload['next']);
    }

    public function test_context_reads_the_same_project_scope_as_the_audit(): void
    {
        // Until the scope reached the context, the audit reported findings in routes/
        // while the context said nothing depended on the symbol used there.
        $this->writeFixture();
        $this->writeFile('routes/web.php', <<<'PHP'
<?php

use App\Actions\FetchDocument;

$action = new FetchDocument();
$action->handle();
PHP);

        $withoutScope = $this->dependentsOf('App\Actions\FetchDocument');
        $this->assertNotContains('routes/web.php', $withoutScope);

        $this->writeRawConfig(<<<'PHP'
<?php

use GracjanKubicki\ArchitectureKit\Architecture;

return [
    'enabled' => [
        Architecture::Actions,
        Architecture::PortsAndAdapters,
    ],
    'audit' => [
        'paths' => ['routes'],
    ],
];
PHP);

        $this->assertContains('routes/web.php', $this->dependentsOf('App\Actions\FetchDocument'));
    }

    /**
     * @return array<int, string>
     */
    private function dependentsOf(string $subject): array
    {
        Artisan::call('architecture-kit:context', ['subject' => $subject, '--agent' => true]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        return array_values(array_map(
            static fn (array $relation): string => (string) $relation['evidence']['path'],
            $payload['dependents'] ?? [],
        ));
    }

    private function writeRawConfig(string $contents): void
    {
        $this->writeFile('config/architectures.php', $contents);
    }

    /** @param array<int, Architecture|string> $enabled */
    private function writeConfig(array $enabled): void
    {
        (new ArchitectureConfig($this->tempPath.'/config/architectures.php'))->write($enabled);
    }

    private function writeFile(string $path, string $contents): void
    {
        $files = new Filesystem;
        $absolute = $this->tempPath.'/'.$path;
        $files->ensureDirectoryExists(dirname($absolute));
        $files->put($absolute, $contents);
    }
}
