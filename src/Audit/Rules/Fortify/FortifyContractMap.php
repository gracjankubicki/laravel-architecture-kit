<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Audit\Rules\Fortify;

final readonly class FortifyContractMap
{
    /** @var array<string, string> */
    private const ACTION_CONTRACTS = [
        'Laravel\Fortify\Contracts\CreatesNewUsers' => 'create',
        'Laravel\Fortify\Contracts\ResetsUserPasswords' => 'reset',
        'Laravel\Fortify\Contracts\UpdatesUserProfileInformation' => 'update',
        'Laravel\Fortify\Contracts\UpdatesUserPasswords' => 'update',
    ];

    /** @var array<string, string> */
    private const RESPONSE_CONTRACTS = [
        'Laravel\Fortify\Contracts\ConfirmPasswordViewResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\EmailVerificationNotificationSentResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\FailedPasswordConfirmationResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\FailedPasswordResetResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\LockoutResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\LoginResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\LoginViewResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\LogoutResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\PasswordConfirmedResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\PasswordResetResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\PasswordUpdateResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\ProfileInformationUpdatedResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\RecoveryCodesGeneratedResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\RegisterResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\RegisterViewResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\RequestPasswordResetLinkViewResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\ResetPasswordViewResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\TwoFactorChallengeViewResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\TwoFactorConfirmedResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\TwoFactorDisabledResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\TwoFactorEnabledResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\TwoFactorLoginResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\VerifyEmailResponse' => 'toResponse',
        'Laravel\Fortify\Contracts\VerifyEmailViewResponse' => 'toResponse',
    ];

    /** @var array<string, string> */
    private const ACTION_REGISTRATIONS = [
        'createusersusing' => 'Laravel\Fortify\Contracts\CreatesNewUsers',
        'resetuserpasswordsusing' => 'Laravel\Fortify\Contracts\ResetsUserPasswords',
        'updateuserprofileinformationusing' => 'Laravel\Fortify\Contracts\UpdatesUserProfileInformation',
        'updateuserpasswordsusing' => 'Laravel\Fortify\Contracts\UpdatesUserPasswords',
    ];

    /** @return array<string, string> */
    public static function actionContracts(): array
    {
        return self::ACTION_CONTRACTS;
    }

    /** @return array<string, string> */
    public static function responseContracts(): array
    {
        return self::RESPONSE_CONTRACTS;
    }

    /** @return array<string, string> */
    public static function actionRegistrations(): array
    {
        return self::ACTION_REGISTRATIONS;
    }

    public static function actionContractForRegistration(string $method): ?string
    {
        return self::ACTION_REGISTRATIONS[strtolower($method)] ?? null;
    }

    public static function methodFor(string $contract): ?string
    {
        return self::ACTION_CONTRACTS[$contract]
            ?? self::RESPONSE_CONTRACTS[$contract]
            ?? null;
    }

    public static function isResponseContract(string $contract): bool
    {
        return isset(self::RESPONSE_CONTRACTS[$contract]);
    }
}
