<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\Rules\Fortify\FortifyContractMap;
use InvalidArgumentException;

/** Source Fortify registrations share the audit's action contract map. */
final class CatalogFortifyRegistrations
{
    public const VIEWS = [
        'loginview' => 'LoginViewResponse', 'registerview' => 'RegisterViewResponse', 'resetpasswordview' => 'ResetPasswordViewResponse',
        'verifyemailview' => 'VerifyEmailViewResponse', 'confirmpasswordview' => 'ConfirmPasswordViewResponse',
        'requestpasswordresetlinkview' => 'RequestPasswordResetLinkViewResponse', 'twofactorchallengeview' => 'TwoFactorChallengeViewResponse',
    ];

    public const CALLBACKS = ['authenticateusing', 'authenticatethrough', 'loginthrough', 'confirmpasswordsusing', 'generaterecoverycodesusing'];

    public const CONTROLLERS = [
        'createusersusing' => 'RegisteredUserController::store', 'resetuserpasswordsusing' => 'NewPasswordController::store',
        'updateuserprofileinformationusing' => 'ProfileInformationController::update', 'updateuserpasswordsusing' => 'PasswordController::update',
        'loginview' => 'AuthenticatedSessionController::create', 'registerview' => 'RegisteredUserController::create',
        'resetpasswordview' => 'NewPasswordController::create', 'verifyemailview' => 'EmailVerificationPromptController::__invoke',
        'confirmpasswordview' => 'ConfirmablePasswordController::show', 'requestpasswordresetlinkview' => 'PasswordResetLinkController::create',
        'twofactorchallengeview' => 'TwoFactorAuthenticatedSessionController::create',
        'authenticateusing' => 'AuthenticatedSessionController::store', 'authenticatethrough' => 'AuthenticatedSessionController::store',
        'loginthrough' => 'AuthenticatedSessionController::store', 'confirmpasswordsusing' => 'ConfirmablePasswordController::store',
        'generaterecoverycodesusing' => 'RecoveryCodeController::store',
    ];

    public static function supported(string $method): bool
    {
        return FortifyContractMap::actionContractForRegistration($method) !== null || isset(self::VIEWS[$method]) || in_array($method, self::CALLBACKS, true);
    }

    public static function contract(string $method): ?string
    {
        return FortifyContractMap::actionContractForRegistration($method)
            ?? (isset(self::VIEWS[$method]) ? 'Laravel\\Fortify\\Contracts\\'.self::VIEWS[$method] : null);
    }

    /** @param array<string, mixed> $metadata */
    public static function validate(array $metadata): void
    {
        if (array_keys($metadata) !== ['package', 'method', 'contract', 'target', 'callback', 'view', 'pipeline', 'pipeline_resolved', 'resolved', 'execution_proven']
            || $metadata['package'] !== 'laravel/fortify' || ! is_string($metadata['method']) || ! self::supported($metadata['method'])
            || $metadata['contract'] !== self::contract($metadata['method'])
            || $metadata['target'] !== null && (! is_string($metadata['target']) || $metadata['target'] === '' || strlen($metadata['target']) > 1000)
            || $metadata['callback'] !== null && (! is_string($metadata['callback']) || preg_match('/\Aelement:[a-f0-9]{32}\z/D', $metadata['callback']) !== 1)
            || $metadata['view'] !== null && (! is_string($metadata['view']) || preg_match('/\A[a-zA-Z0-9_][a-zA-Z0-9_.\/:\-]{0,255}\z/D', $metadata['view']) !== 1)
            || ! is_array($metadata['pipeline']) || ! array_is_list($metadata['pipeline']) || count($metadata['pipeline']) > 128
            || ! is_bool($metadata['pipeline_resolved']) || ! is_bool($metadata['resolved']) || $metadata['execution_proven'] !== false) {
            throw new InvalidArgumentException('Invalid Fortify source registration.');
        }
        foreach ($metadata['pipeline'] as $target) {
            if (! is_string($target) || $target === '' || strlen($target) > 1000) {
                throw new InvalidArgumentException('Invalid Fortify pipeline target.');
            }
        }
    }
}
