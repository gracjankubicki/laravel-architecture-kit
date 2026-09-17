<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Framework;

use GracjanKubicki\ArchitectureKit\Audit\ReadSide\SourceIndex;

final readonly class FortifySemantics
{
    /** @var array<string, array{kind: string, registration?: string, contract?: string}> */
    private const ENTRYPOINTS = [
        'Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::create' => ['kind' => 'view', 'registration' => 'loginview', 'contract' => 'Laravel\Fortify\Contracts\LoginViewResponse'],
        'Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::store' => ['kind' => 'authentication'],
        'Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::destroy' => ['kind' => 'vendor'],
        'Laravel\Fortify\Http\Controllers\RegisteredUserController::create' => ['kind' => 'view', 'registration' => 'registerview', 'contract' => 'Laravel\Fortify\Contracts\RegisterViewResponse'],
        'Laravel\Fortify\Http\Controllers\RegisteredUserController::store' => ['kind' => 'action', 'registration' => 'createUsers'],
        'Laravel\Fortify\Http\Controllers\PasswordResetLinkController::create' => ['kind' => 'view', 'registration' => 'requestpasswordresetlinkview', 'contract' => 'Laravel\Fortify\Contracts\RequestPasswordResetLinkViewResponse'],
        'Laravel\Fortify\Http\Controllers\PasswordResetLinkController::store' => ['kind' => 'vendor'],
        'Laravel\Fortify\Http\Controllers\NewPasswordController::create' => ['kind' => 'view', 'registration' => 'resetpasswordview', 'contract' => 'Laravel\Fortify\Contracts\ResetPasswordViewResponse'],
        'Laravel\Fortify\Http\Controllers\NewPasswordController::store' => ['kind' => 'action', 'registration' => 'resetUserPasswords'],
        'Laravel\Fortify\Http\Controllers\ConfirmablePasswordController::show' => ['kind' => 'view', 'registration' => 'confirmpasswordview', 'contract' => 'Laravel\Fortify\Contracts\ConfirmPasswordViewResponse'],
        'Laravel\Fortify\Http\Controllers\ConfirmablePasswordController::store' => ['kind' => 'vendor'],
        'Laravel\Fortify\Http\Controllers\ConfirmedPasswordStatusController::show' => ['kind' => 'vendor'],
        'Laravel\Fortify\Http\Controllers\EmailVerificationPromptController::__invoke' => ['kind' => 'view', 'registration' => 'verifyemailview', 'contract' => 'Laravel\Fortify\Contracts\VerifyEmailViewResponse'],
        'Laravel\Fortify\Http\Controllers\EmailVerificationNotificationController::store' => ['kind' => 'vendor'],
        'Laravel\Fortify\Http\Controllers\VerifyEmailController::__invoke' => ['kind' => 'vendor'],
        'Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController::create' => ['kind' => 'view', 'registration' => 'twofactorchallengeview', 'contract' => 'Laravel\Fortify\Contracts\TwoFactorChallengeViewResponse'],
        'Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController::store' => ['kind' => 'vendor'],
        'Laravel\Fortify\Http\Controllers\ProfileInformationController::update' => ['kind' => 'action', 'registration' => 'updateUserProfileInformation'],
        'Laravel\Fortify\Http\Controllers\PasswordController::update' => ['kind' => 'action', 'registration' => 'updateUserPasswords'],
        'Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController::store' => ['kind' => 'action', 'registration' => 'enableTwoFactorAuthentication'],
        'Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController::destroy' => ['kind' => 'action', 'registration' => 'disableTwoFactorAuthentication'],
        'Laravel\Fortify\Http\Controllers\ConfirmedTwoFactorAuthenticationController::store' => ['kind' => 'action', 'registration' => 'confirmTwoFactorAuthentication'],
        'Laravel\Fortify\Http\Controllers\TwoFactorQrCodeController::show' => ['kind' => 'vendor'],
        'Laravel\Fortify\Http\Controllers\TwoFactorSecretKeyController::show' => ['kind' => 'vendor'],
        'Laravel\Fortify\Http\Controllers\RecoveryCodeController::index' => ['kind' => 'vendor'],
        'Laravel\Fortify\Http\Controllers\RecoveryCodeController::store' => ['kind' => 'vendor'],
    ];

    public function __construct(private SourceIndex $sources, private FrameworkContext $context) {}

    public function entrypoint(string $class, string $method): FrameworkCallResult
    {
        $key = ltrim($class, '\\').'::'.$method;
        $entrypoint = self::ENTRYPOINTS[$key] ?? null;
        if ($entrypoint === null) {
            return str_starts_with(ltrim($class, '\\'), 'Laravel\\Fortify\\Http\\Controllers\\')
                ? new FrameworkCallResult(true, incomplete: 'Unsupported Fortify endpoint '.$key.'.')
                : FrameworkCallResult::unhandled();
        }

        return match ($entrypoint['kind']) {
            'vendor' => FrameworkCallResult::value(FrameworkValue::type('@response')),
            'authentication' => $this->authentication($key),
            'view' => $this->view($key, $entrypoint['registration'], $entrypoint['contract']),
            'action' => $this->action($key, $entrypoint['registration']),
        };
    }

    private function authentication(string $key): FrameworkCallResult
    {
        if ($this->context->status === FrameworkContext::UNAVAILABLE) {
            return new FrameworkCallResult(true, incomplete: $this->context->unavailable ?? 'Fortify authentication context is unavailable for '.$key.'.');
        }

        $callbacks = [];
        if (isset($this->context->fortifyCallbacks['authenticateusing'])) {
            $callbacks[] = $this->context->fortifyCallbacks['authenticateusing'];
        }

        return new FrameworkCallResult(true, FrameworkValue::type('@response'), $this->context->fortifyPipeline, $callbacks);
    }

    private function view(string $key, string $registration, string $contract): FrameworkCallResult
    {
        $callback = $this->context->fortifyViews[$registration] ?? null;
        if ($callback !== null) {
            return FrameworkCallResult::callbacks([$callback], FrameworkValue::type('@response'));
        }

        $implementation = $this->context->bindings[$contract] ?? null;
        if ($implementation !== null) {
            foreach (['toResponse', '__invoke'] as $method) {
                if ($this->sources->method($implementation, $method) !== null) {
                    return new FrameworkCallResult(true, FrameworkValue::type('@response'), [['class' => $implementation, 'method' => $method]]);
                }
            }

            return new FrameworkCallResult(true, incomplete: 'Fortify response binding '.$implementation.' has no resolvable response method for '.$key.'.');
        }

        if ($this->context->status === FrameworkContext::UNAVAILABLE) {
            return new FrameworkCallResult(true, incomplete: $this->context->unavailable ?? 'Fortify view context is unavailable for '.$key.'.');
        }

        return FrameworkCallResult::value(FrameworkValue::type('@response'));
    }

    private function action(string $key, string $registration): FrameworkCallResult
    {
        $target = $this->context->fortifyActions[$registration] ?? null;
        if ($target instanceof FrameworkValue) {
            return FrameworkCallResult::callbacks([$target], FrameworkValue::type('@response'));
        }
        if (is_array($target)) {
            return new FrameworkCallResult(true, FrameworkValue::type('@response'), [$target]);
        }

        return new FrameworkCallResult(
            true,
            incomplete: $this->context->unavailable ?? 'Fortify registration '.$registration.' is unavailable for '.$key.'.',
        );
    }
}
