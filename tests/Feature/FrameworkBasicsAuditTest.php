<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\ReadSide\RouteMap;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class FrameworkBasicsAuditTest extends TestCase
{
    public function test_standard_request_redirect_inertia_and_fortify_feature_calls_are_known(): void
    {
        $this->write('app/Http/Requests/ProfileRequest.php', '<?php namespace App\Http\Requests; final class ProfileRequest extends \Illuminate\Foundation\Http\FormRequest {}');
        $this->controller(<<<'PHP'
public function show(\App\Http\Requests\ProfileRequest $request): \Inertia\Response {
    $request->user();
    $request->validated();
    $request->session()->get('status');
    \Laravel\Fortify\Features::canManageTwoFactorAuthentication();
    to_route('home');
    return \Inertia\Inertia::render('Profile/Edit', ['status' => session()->get('status')]);
}
PHP);

        $this->assertSame([], $this->thinFindings());
    }

    public function test_session_reads_are_clean_and_explicit_mutations_on_get_are_reported(): void
    {
        foreach (['put', 'forget', 'flash', 'pull'] as $method) {
            $this->controller("public function show() { return session()->{$method}('status', 'saved'); }");
            $findings = $this->thinFindings();
            $this->assertCount(1, $findings, $method);
            $this->assertSame('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $findings[0]->code);
            $this->assertStringContainsString('Illuminate\\Session\\Store::'.$method.'()', $findings[0]->message);
        }

        $this->controller("public function show() { return session(['status' => 'saved']); }");
        $this->assertSame('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $this->thinFindings()[0]->code);
    }

    public function test_lookalike_session_method_is_not_treated_as_framework(): void
    {
        $this->write('app/Support/LocalStore.php', '<?php namespace App\Support; final class LocalStore { public function put() { \App\Models\Invoice::query()->update([]); } }');
        $this->write('app/Models/Invoice.php', '<?php namespace App\Models; final class Invoice extends \Illuminate\Database\Eloquent\Model {}');
        $this->controller('public function show(\App\Support\LocalStore $store) { return $store->put(); }');

        $this->assertSame('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $this->thinFindings()[0]->code);
    }

    public function test_session_facade_mutation_is_reported_only_for_read_verbs(): void
    {
        $this->controller("public function show() { return \\Illuminate\\Support\\Facades\\Session::flash('status', 'saved'); }");
        $this->assertSame('E_THIN_CONTROLLER_READ_SIDE_EFFECT', $this->thinFindings()[0]->code);

        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [Architecture::ThinControllers, Architecture::Actions],
            changedOnly: false,
            routes: new RouteMap(['app\\http\\controllers\\frameworkcontroller::show' => ['POST']]),
        );

        $this->assertSame([], array_values(array_filter($result->findings, fn ($finding): bool => $finding->rule === 'thin-controller')));
    }

    public function test_unknown_fortify_feature_method_is_not_blanket_whitelisted(): void
    {
        $this->controller('public function show() { return \Laravel\Fortify\Features::canInventFeature(); }');

        $findings = $this->thinFindings();
        $this->assertCount(1, $findings);
        $this->assertSame('W_THIN_CONTROLLER_READ_ANALYSIS_INCOMPLETE', $findings[0]->code);
    }

    private function controller(string $method): void
    {
        $this->write('app/Http/Controllers/FrameworkController.php', '<?php namespace App\Http\Controllers; final class FrameworkController { '.$method.' }');
    }

    /** @return list<object> */
    private function thinFindings(): array
    {
        $result = (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            [Architecture::ThinControllers, Architecture::Actions],
            changedOnly: false,
            routes: new RouteMap(
                ['app\\http\\controllers\\frameworkcontroller::show' => ['GET', 'HEAD']],
                context: [
                    'status' => 'known',
                    'providers' => [],
                    'middleware' => [],
                    'middlewareGroups' => [],
                    'middlewareAliases' => [],
                    'packageVersions' => [],
                ],
            ),
        );

        return array_values(array_filter($result->findings, fn ($finding): bool => $finding->rule === 'thin-controller'));
    }

    private function write(string $path, string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $source);
    }
}
