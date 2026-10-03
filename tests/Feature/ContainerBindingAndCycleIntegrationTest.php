<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Config\ArchitectureConfig;
use GracjanKubicki\ArchitectureKit\Mcp\ArchitectureKitServer;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\AuditChanged;
use GracjanKubicki\ArchitectureKit\Mcp\Tools\Guard;
use GracjanKubicki\ArchitectureKit\Resources\ArchitectureResources;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

final class ContainerBindingAndCycleIntegrationTest extends TestCase
{
    public function test_single_argument_bindings_complete_through_cli_and_mcp_without_executing_factories(): void
    {
        $this->resources();
        $this->withRoutes('<?php Illuminate\Support\Facades\Route::get("/read", [App\Http\Controllers\ReadController::class, "show"]);');
        $this->write('app/Http/Controllers/ReadController.php', '<?php namespace App\Http\Controllers; final class ReadController { public function show(): string { return "ok"; } }');
        $this->write('app/Providers/BindingProvider.php', <<<'PHP'
<?php namespace App\Providers;
final class BindingProvider extends \Illuminate\Support\ServiceProvider {
    public function register(): void {
        $this->app->singleton(fn (): \App\Images\LocalProcessor => new \App\Images\LocalProcessor);
        $this->app->bind(function (): \App\Contracts\Processor { throw new \RuntimeException('Factory must not execute'); });
        $this->app->scoped(fn (): \App\Images\CloudProcessor => new \App\Images\CloudProcessor);
    }
}
PHP);
        $this->write('bootstrap/providers.php', '<?php require_once dirname(__DIR__)."/app/Providers/BindingProvider.php"; return [App\Providers\BindingProvider::class];');
        foreach (['architecture-kit:audit', 'architecture-kit:guard'] as $command) {
            $exit = Artisan::call($command, ['--agent' => true, '--strict' => true]);
            $this->assertSame(0, $exit);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue($payload['ok']);
            $this->assertSame([], $payload['find']);
        }
        foreach ([AuditChanged::class, Guard::class] as $tool) {
            ArchitectureKitServer::tool($tool, ['changed' => false, 'strict' => true])->assertOk()
                ->assertStructuredContent(fn ($json) => $json->where('ok', true)->where('find', [])->etc());
        }
    }

    public function test_baselined_cycle_passes_changed_strict_guard_through_cli_and_mcp(): void
    {
        $this->resources();
        $this->write('app/Models/Image.php', '<?php namespace App\Models; final class Image { public function handle(\App\Traits\Processing $p): void {} }');
        $this->write('app/Traits/Processing.php', '<?php namespace App\Traits; final class Processing { public function handle(\App\Models\Image $p): void {} }');
        foreach ([['init', '-b', 'main'], ['config', 'user.email', 'architecture-kit@example.test'], ['config', 'user.name', 'Architecture Kit Tests'], ['add', '.'], ['commit', '-m', 'fixture']] as $arguments) {
            $process = new Process(['git', ...$arguments], $this->tempPath);
            $this->assertSame(0, $process->run(), $process->getErrorOutput());
        }
        $this->assertSame(0, Artisan::call('architecture-kit:audit', ['--update-baseline' => true]));
        $this->write('app/Traits/Processing.php', '<?php namespace App\Traits; final class Processing { public function handle(\App\Models\Image $p): void { $label = "changed"; } }');
        $exit = Artisan::call('architecture-kit:guard', ['--changed' => true, '--base' => 'main', '--strict' => true, '--agent' => true]);
        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([], $payload['find']);
        foreach ([AuditChanged::class, Guard::class] as $tool) {
            ArchitectureKitServer::tool($tool, ['changed' => true, 'base' => 'main', 'strict' => true])->assertOk()
                ->assertStructuredContent(fn ($json) => $json->where('ok', true)->where('find', [])->etc());
        }
    }

    private function resources(): void
    {
        $files = new Filesystem;
        $enabled = [Architecture::Enums];
        (new ArchitectureConfig($this->tempPath.'/config/architectures.php', $files))->write($enabled);
        $resources = new ArchitectureResources(dirname(__DIR__, 2), $this->tempPath, $files);
        foreach ([$resources->guideline($enabled), ...array_values($resources->skills($enabled))] as $file) {
            $files->ensureDirectoryExists(dirname($file->path));
            $files->put($file->path, $file->contents);
        }
    }

    private function write(string $path, string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $source);
    }
}
