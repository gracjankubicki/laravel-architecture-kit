<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\Cache\ProjectGraphCache;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class FrameworkCompatibilityAuditTest extends TestCase
{
    public function test_cache_off_cold_and_warm_preserve_framework_findings_and_default_missing_test_off(): void
    {
        $this->write('app/Http/Controllers/FrameworkController.php', <<<'PHP'
<?php namespace App\Http\Controllers;
final class FrameworkController {
    public function show() { session()->flash('status', 'saved'); return to_route('home'); }
}
PHP);
        $this->write('app/Services/DisabledService.php', '<?php namespace App\Services; final class DisabledService {}');
        $files = new Filesystem;
        $audit = new ApplicationAudit($files, $this->tempPath);
        $routes = $this->routes();
        $cache = new ProjectGraphCache($files, $this->tempPath);
        $run = fn ($graphCache) => $audit->run([Architecture::ThinControllers, Architecture::Actions], false, cache: $graphCache, routes: $routes);

        $off = $run(null);
        $cold = $run($cache);
        $warm = $run($cache);

        $this->assertNotSame([], $off->suggestions);
        $this->assertEquals($off->suggestions, $cold->suggestions);
        $this->assertEquals($cold->suggestions, $warm->suggestions);
        $this->assertNotContains('W_MISSING_TEST', array_column($warm->findings, 'code'));
    }

    public function test_changed_resource_rechecks_unchanged_controller(): void
    {
        $this->write('app/Models/Project.php', '<?php namespace App\Models; final class Project extends \Illuminate\Database\Eloquent\Model {}');
        $this->write('app/Http/Resources/ProjectResource.php', '<?php namespace App\Http\Resources; final class ProjectResource extends \Illuminate\Http\Resources\Json\JsonResource { public function toArray($request): array { return []; } }');
        $this->write('app/Http/Controllers/FrameworkController.php', '<?php namespace App\Http\Controllers; final class FrameworkController { public function show() { return \App\Http\Resources\ProjectResource::make([]); } }');
        foreach ([['git', 'init', '-q'], ['git', 'add', 'app'], ['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '-qm', 'fixture']] as $command) {
            (new Process($command, $this->tempPath))->mustRun();
        }
        $this->write('app/Http/Resources/ProjectResource.php', '<?php namespace App\Http\Resources; final class ProjectResource extends \Illuminate\Http\Resources\Json\JsonResource { public function toArray($request): array { \App\Models\Project::query()->update([]); return []; } }');

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [Architecture::ThinControllers, Architecture::Actions],
            changedOnly: true,
            routes: $this->routes(),
        );

        $this->assertContains('S_MOVE_WRITE_TO_ACTION', array_column($result->suggestions, 'code'));
    }

    private function routes(): RouteMap
    {
        return new RouteMap(
            ['app\\http\\controllers\\frameworkcontroller::show' => ['GET', 'HEAD']],
            context: [
                'status' => 'known',
                'providers' => [],
                'middleware' => [],
                'middlewareGroups' => [],
                'middlewareAliases' => [],
                'packageVersions' => [],
            ],
        );
    }

    private function write(string $path, string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $source);
    }
}
