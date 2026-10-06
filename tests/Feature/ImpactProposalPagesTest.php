<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Impact\ImpactPages;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Impact;
use GracjanKubicki\ArchitectureKit\Reach\ArchitectureReach;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Laravel\Mcp\Server\Transport\FakeTransporter;

final class ImpactProposalPagesTest extends TestCase
{
    private function fixture(): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->tempPath.'/app');
        $files->ensureDirectoryExists($this->tempPath.'/config');
        $files->put($this->tempPath.'/config/architectures.php', '<?php return [];');
        $files->put($this->tempPath.'/app/Payment.php', '<?php namespace App; class Payment { public static function charge(int $amount): void {} }');
        foreach (['A', 'B', 'C'] as $name) {
            $files->put($this->tempPath.'/app/'.$name.'.php', '<?php namespace App; class '.$name.' { public function go() { Payment::charge(1); } }');
        }
        clearstatcache();
    }

    public function test_saved_signature_pages_preserve_proposal_and_reject_changed_sources(): void
    {
        $this->fixture();
        $pages = new ImpactPages(new Filesystem, $this->tempPath);
        $first = $pages->inspect('Payment::charge', 1, signature: 'public static function charge(int $amount, string $currency): void');
        $this->assertTrue($first['ok'], json_encode($first));
        $this->assertCount(1, $first['signature']['breaking']);
        $this->assertSame(3, $first['signature']['total']['breaking']);
        $next = $pages->inspect('', 1, reportId: $first['pagination']['report_id'], page: 2);
        $this->assertTrue($next['ok'], json_encode($next));
        $this->assertSame($first['normalized_intent'], $next['normalized_intent']);
        $this->assertSame($first['snapshot'], $next['snapshot']);
        $this->assertNotSame($first['signature']['breaking'], $next['signature']['breaking']);
        $this->assertFalse($pages->inspect('Payment::charge', 1, reportId: $first['pagination']['report_id'], page: 2)['ok']);
        (new Filesystem)->append($this->tempPath.'/app/A.php', ' // changed');
        clearstatcache();
        $this->assertFalse($pages->inspect('', 1, reportId: $first['pagination']['report_id'], page: 2)['ok']);
    }

    public function test_zero_display_limit_retains_checks_and_continuation_never_reads_php(): void
    {
        $this->fixture();
        $pages = new ImpactPages(new Filesystem, $this->tempPath);
        $first = $pages->inspect('Payment::charge', 0, change: 'delete');
        $this->assertTrue($first['ok'], json_encode($first));
        $this->assertSame([], $first['delete']['breaking']);
        $this->assertSame(3, $first['delete']['total']['breaking']);
        $files = new class extends Filesystem
        {
            public function get($path, $lock = false)
            {
                if (str_ends_with($path, '.php')) {
                    throw new \RuntimeException('Continuation read a PHP body');
                }

                return parent::get($path, $lock);
            }
        };
        $next = (new ImpactPages($files, $this->tempPath))->inspect('', 1, reportId: $first['pagination']['report_id']);
        $this->assertTrue($next['ok'], json_encode($next));
        $this->assertCount(1, $next['delete']['breaking']);
        $this->assertFalse($pages->inspect('', 1, reportId: $first['pagination']['report_id'], change: 'move')['ok']);
        $reach = (new ArchitectureReach(new Filesystem, $this->tempPath))->inspect('', 1, reportId: $first['pagination']['report_id']);
        $this->assertSame('E_REACH_INPUT', $reach['m']);
    }

    public function test_cli_and_mcp_page_the_same_proposal_report(): void
    {
        $this->fixture();
        $this->assertSame(0, Artisan::call('architecture-kit:impact', ['subject' => 'Payment::charge', '--change' => 'delete', '--page' => '1', '--limit' => '1', '--agent' => true]));
        $first = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $arguments = ['report_id' => $first['pagination']['report_id'], 'page' => 2, 'limit' => 1];
        $mcp = ArchitectureKitServer::tool(Impact::class, $arguments);
        $mcp->assertOk();
        $this->assertSame(0, Artisan::call('architecture-kit:impact', ['--report' => $arguments['report_id'], '--page' => '2', '--limit' => '1', '--agent' => true]));
        $cli = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $mcp->assertStructuredContent($cli);
        $this->assertNotSame(0, Artisan::call('architecture-kit:impact', ['--report' => $arguments['report_id'], '--depth' => '8', '--agent' => true]));
        ArchitectureKitServer::tool(Impact::class, [...$arguments, 'depth' => 8])->assertStructuredContent(fn ($json) => $json->where('ok', false)->etc());
    }

    public function test_actual_tool_and_generated_guidance_describe_proposal_continuation(): void
    {
        $context = (new ArchitectureKitServer(new FakeTransporter))->createContext();
        $tool = $context->tools()->first(fn ($tool) => $tool->name() === 'impact')->toArray();
        foreach ($tool['inputSchema']['properties'] as $name => $property) {
            $this->assertNotEmpty($property['description'] ?? null, $name);
        }
        $this->assertStringContainsString('pagination.next', $tool['description']);
        $resources = new ArchitectureResources(dirname(__DIR__, 2), $this->tempPath);
        foreach ([$resources->guideline([Architecture::Actions])->contents, $resources->fullGuideline([Architecture::Actions]), $context->instructions, Blade::render(file_get_contents(dirname(__DIR__, 2).'/resources/boost/guidelines/core.blade.php'))] as $text) {
            $this->assertStringContainsString('pagination.next', $text);
            $this->assertStringContainsString('proposal', $text);
        }
    }

    public function test_file_move_error_requires_class_selection_without_claiming_classless_source(): void
    {
        $this->fixture();
        $result = (new ImpactPages(new Filesystem, $this->tempPath))->inspect('app/Payment.php', change: 'move', targetClass: 'App\\Renamed', targetPath: 'app/Renamed.php');
        $this->assertFalse($result['ok']);
        $this->assertSame('E_IMPACT_MOVE_TARGET_INVALID', $result['m']);
        $this->assertStringContainsString('explicit class selector', $result['msg']);
    }
}
