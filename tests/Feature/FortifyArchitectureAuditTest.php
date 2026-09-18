<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Feature;

use GracjanKubicki\ArchitectureKit\Architecture;
use GracjanKubicki\ArchitectureKit\Audit\ApplicationAudit;
use GracjanKubicki\ArchitectureKit\Audit\AuditFinding;
use GracjanKubicki\ArchitectureKit\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

final class FortifyArchitectureAuditTest extends TestCase
{
    public function test_standard_fortify_action_registrations_accept_their_contracts_and_native_methods(): void
    {
        $this->write('app/Actions/CreateNewUser.php', <<<'PHP'
<?php
namespace App\Actions;
use Laravel\Fortify\Contracts\CreatesNewUsers;
final class CreateNewUser implements CreatesNewUsers { public function create(array $input): object { return (object) $input; } }
PHP);
        $this->write('app/Actions/ResetUserPassword.php', <<<'PHP'
<?php
namespace App\Actions;
final class ResetUserPassword implements \Laravel\Fortify\Contracts\ResetsUserPasswords { public function reset(object $user, array $input): void {} }
PHP);
        $this->write('app/Actions/UpdateUserProfile.php', <<<'PHP'
<?php
namespace App\Actions;
final class UpdateUserProfile implements \Laravel\Fortify\Contracts\UpdatesUserProfileInformation { public function update(object $user, array $input): void {} }
PHP);
        $this->write('app/Actions/UpdateUserPassword.php', <<<'PHP'
<?php
namespace App\Actions;
final class UpdateUserPassword implements \Laravel\Fortify\Contracts\UpdatesUserPasswords { public function update(object $user, array $input): void {} }
PHP);
        $this->write('app/Providers/FortifyServiceProvider.php', <<<'PHP'
<?php
namespace App\Providers;
use App\Actions\CreateNewUser;
use Laravel\Fortify\Fortify as FortifyManager;
final class FortifyServiceProvider {
    public function boot(): void {
        FortifyManager::createUsersUsing(CreateNewUser::class);
        \Laravel\Fortify\Fortify::resetUserPasswordsUsing(\App\Actions\ResetUserPassword::class);
        FortifyManager::updateUserProfileInformationUsing(\App\Actions\UpdateUserProfile::class);
        FortifyManager::updateUserPasswordsUsing(\App\Actions\UpdateUserPassword::class);
    }
}
PHP);

        $this->assertSame([], $this->findings([Architecture::Fortify]));
    }

    public function test_a_registered_action_must_implement_the_required_contract(): void
    {
        $this->write('app/Actions/CreateNewUser.php', '<?php namespace App\Actions; final class CreateNewUser { public function create(array $input): object { return (object) $input; } }');
        $this->write('app/Providers/FortifyServiceProvider.php', '<?php namespace App\Providers; final class FortifyServiceProvider { public function boot(): void { \Laravel\Fortify\Fortify::createUsersUsing(\App\Actions\CreateNewUser::class); } }');

        $findings = $this->findings([Architecture::Fortify]);

        $this->assertContains('E_FORTIFY_CONTRACT_MISMATCH', array_column($findings, 'code'));
        $this->assertContains('folder-purity', array_column($findings, 'rule'));
    }

    public function test_a_registered_action_must_expose_the_native_method_publicly(): void
    {
        $this->write('app/Actions/CreateNewUser.php', '<?php namespace App\Actions; final class CreateNewUser implements \Laravel\Fortify\Contracts\CreatesNewUsers { private function create(array $input): object { return (object) $input; } }');
        $this->write('app/Providers/FortifyServiceProvider.php', '<?php namespace App\Providers; final class FortifyServiceProvider { public function boot(): void { \Laravel\Fortify\Fortify::createUsersUsing(\App\Actions\CreateNewUser::class); } }');

        $findings = $this->findings([Architecture::Fortify]);

        $this->assertContains('E_FORTIFY_METHOD_MISMATCH', array_column($findings, 'code'));
        $this->assertContains('folder-purity', array_column($findings, 'rule'));
    }

    public function test_dynamic_action_registration_is_reported_as_incomplete_once(): void
    {
        $this->write('app/Providers/FortifyServiceProvider.php', '<?php namespace App\Providers; final class FortifyServiceProvider { public function boot(string $action): void { \Laravel\Fortify\Fortify::createUsersUsing($action); } }');

        $findings = array_values(array_filter(
            $this->findings([Architecture::Fortify]),
            fn (AuditFinding $finding): bool => $finding->code === 'W_FORTIFY_ANALYSIS_INCOMPLETE',
        ));

        $this->assertCount(1, $findings);
        $this->assertSame('app/Providers/FortifyServiceProvider.php', $findings[0]->path);
    }

    public function test_fortify_response_contracts_are_allowed_but_unrelated_response_classes_are_not(): void
    {
        $this->write('app/Http/Responses/LoginResponse.php', <<<'PHP'
<?php
namespace App\Http\Responses;
final class LoginResponse implements \Laravel\Fortify\Contracts\LoginViewResponse { public function toResponse($request): mixed { return null; } }
PHP);
        $this->write('app/Http/Responses/OtherResponse.php', '<?php namespace App\Http\Responses; final class OtherResponse { public function toResponse($request): mixed { return null; } }');
        $this->write('app/Providers/FortifyServiceProvider.php', <<<'PHP'
<?php
namespace App\Providers;
final class FortifyServiceProvider {
    public function register(): void {
        $this->app->singleton(\Laravel\Fortify\Contracts\LoginViewResponse::class, \App\Http\Responses\LoginResponse::class);
    }
}
PHP);

        $findings = $this->findings([Architecture::Fortify]);
        $unenabledPaths = array_column(array_filter($findings, fn (AuditFinding $finding): bool => $finding->rule === 'unenabled-pattern'), 'path');

        $this->assertNotContains('app/Http/Responses/LoginResponse.php', $unenabledPaths);
        $this->assertContains('app/Http/Responses/OtherResponse.php', $unenabledPaths);
        $this->assertNotContains('E_FORTIFY_CONTRACT_MISMATCH', array_column($findings, 'code'));
    }

    public function test_response_binding_checks_the_contract_and_public_method(): void
    {
        $this->write('app/Http/Responses/LoginResponse.php', '<?php namespace App\Http\Responses; final class LoginResponse { private function toResponse($request): mixed { return null; } }');
        $this->write('app/Providers/FortifyServiceProvider.php', <<<'PHP'
<?php
namespace App\Providers;
final class FortifyServiceProvider {
    public function register(): void {
        $this->app->singleton(\Laravel\Fortify\Contracts\LoginViewResponse::class, \App\Http\Responses\LoginResponse::class);
    }
}
PHP);

        $findings = $this->findings([Architecture::Fortify]);

        $this->assertContains('E_FORTIFY_CONTRACT_MISMATCH', array_column($findings, 'code'));
    }

    public function test_response_binding_requires_public_to_response_on_the_matching_contract(): void
    {
        $this->write('app/Http/Responses/LoginResponse.php', '<?php namespace App\Http\Responses; final class LoginResponse implements \Laravel\Fortify\Contracts\LoginViewResponse { private function toResponse($request): mixed { return null; } }');
        $this->write('app/Providers/FortifyServiceProvider.php', <<<'PHP'
<?php
namespace App\Providers;
final class FortifyServiceProvider {
    public function register(): void {
        $this->app->singleton(\Laravel\Fortify\Contracts\LoginViewResponse::class, \App\Http\Responses\LoginResponse::class);
    }
}
PHP);

        $findings = $this->findings([Architecture::Fortify]);

        $this->assertContains('E_FORTIFY_METHOD_MISMATCH', array_column($findings, 'code'));
    }

    public function test_unrelated_bind_api_is_not_treated_as_a_fortify_container_binding(): void
    {
        $this->write('app/Providers/OtherProvider.php', <<<'PHP'
<?php
namespace App\Providers;
final class OtherProvider {
    public function register(): void {
        $this->registry->bind(\Laravel\Fortify\Contracts\LoginResponse::class, \App\Support\OtherResponse::class);
    }
}
PHP);

        $findings = $this->findings([Architecture::Fortify]);

        $this->assertNotContains('E_FORTIFY_CONTRACT_MISMATCH', array_column($findings, 'code'));
        $this->assertNotContains('W_FORTIFY_ANALYSIS_INCOMPLETE', array_column($findings, 'code'));
    }

    public function test_dynamic_response_binding_is_reported_as_incomplete(): void
    {
        $this->write('app/Providers/FortifyServiceProvider.php', <<<'PHP'
<?php
namespace App\Providers;
final class FortifyServiceProvider {
    public function register(): void {
        $this->app->singleton(\Laravel\Fortify\Contracts\LoginViewResponse::class, fn () => new \stdClass());
    }
}
PHP);

        $this->assertContains('W_FORTIFY_ANALYSIS_INCOMPLETE', array_column($this->findings([Architecture::Fortify]), 'code'));
    }

    public function test_contract_exceptions_do_not_apply_when_the_profile_is_disabled(): void
    {
        $this->write('app/Actions/CreateNewUser.php', '<?php namespace App\Actions; final class CreateNewUser implements \Laravel\Fortify\Contracts\CreatesNewUsers { public function create(array $input): object { return (object) $input; } }');
        $this->write('app/Http/Responses/LoginResponse.php', '<?php namespace App\Http\Responses; final class LoginResponse implements \Laravel\Fortify\Contracts\LoginViewResponse { public function toResponse($request): mixed { return null; } }');
        $this->write('app/Providers/FortifyServiceProvider.php', '<?php namespace App\Providers; final class FortifyServiceProvider { public function boot(string $action): void { \Laravel\Fortify\Fortify::createUsersUsing($action); } }');

        $findings = $this->findings([]);

        $this->assertContains('folder-purity', array_column($findings, 'rule'));
        $this->assertContains('unenabled-pattern', array_column($findings, 'rule'));
        $this->assertNotContains('W_FORTIFY_ANALYSIS_INCOMPLETE', array_column($findings, 'code'));
    }

    public function test_similar_names_and_unrelated_apis_do_not_receive_fortify_exceptions_or_findings(): void
    {
        $this->write('app/Actions/CreateNewUser.php', '<?php namespace App\Actions; final class CreateNewUser { public function create(array $input): object { return (object) $input; } }');
        $this->write('app/Providers/OtherProvider.php', '<?php namespace App\Providers; final class OtherProvider { public function boot(): void { \App\Support\Fortify::createUsersUsing(\App\Actions\CreateNewUser::class); } }');

        $findings = $this->findings([Architecture::Fortify]);

        $this->assertContains('folder-purity', array_column($findings, 'rule'));
        $this->assertNotContains('E_FORTIFY_CONTRACT_MISMATCH', array_column($findings, 'code'));
        $this->assertNotContains('W_FORTIFY_ANALYSIS_INCOMPLETE', array_column($findings, 'code'));
    }

    public function test_fortify_findings_are_unchanged_when_inertia_is_also_enabled(): void
    {
        $this->write('app/Actions/CreateNewUser.php', '<?php namespace App\Actions; final class CreateNewUser { public function create(array $input): object { return (object) $input; } }');
        $this->write('app/Providers/FortifyServiceProvider.php', '<?php namespace App\Providers; final class FortifyServiceProvider { public function boot(): void { \Laravel\Fortify\Fortify::createUsersUsing(\App\Actions\CreateNewUser::class); } }');

        $fortifyCodes = array_column($this->findings([Architecture::Fortify]), 'code');
        $combinedCodes = array_column($this->findings([Architecture::Inertia, Architecture::Fortify]), 'code');

        $this->assertSame($fortifyCodes, $combinedCodes);
        $this->assertContains('E_FORTIFY_CONTRACT_MISMATCH', $combinedCodes);
    }

    /** @param array<int, Architecture> $enabled
     * @return array<int, AuditFinding>
     */
    private function findings(array $enabled): array
    {
        return (new ApplicationAudit(new Filesystem, $this->tempPath))->run(
            $enabled,
            changedOnly: false,
        )->findings;
    }

    private function write(string $path, string $source): void
    {
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($this->tempPath.'/'.$path));
        $files->put($this->tempPath.'/'.$path, $source);
    }
}
