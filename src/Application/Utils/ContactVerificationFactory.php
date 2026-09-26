<?php
declare(strict_types=1);

namespace Application\Utils;

use Core\Config\LoadCountry;
use Core\Database\DBConnection;
use Core\Factories\CommunicationFactory;
use Domain\Identity\ContactVerificationService;
use Infrastructure\Credentials\CredentialsRepository;
use Infrastructure\Email\EmailGatewayClient;
use RuntimeException;
use Security\Auth\LoginPinVerifier;

/**
 * Builds ContactVerificationService with the real database, credentials
 * database, email gateway and SMS gateway, for the dashboard endpoints
 * public/user/contact_verify_start.php and contact_verify_confirm.php.
 */
final class ContactVerificationFactory
{
    /** Session key holding the pending step between start and confirm. */
    public const SESSION_KEY = 'contact_verification';

    public static function service(): ContactVerificationService
    {
        $db = DBConnection::getConnection();
        if (!$db) {
            throw new RuntimeException('Database connection failed');
        }
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $config = LoadCountry::getConfig();
        $systemCountry = $config['country'] ?? 'BW';
        // Same source and defaults as the sign-in and sign-up pages.
        $countryConfig = $config['country_settings'][$systemCountry] ?? [];
        $dialCode = $countryConfig['dial_code'] ?? '+267';
        $localLength = (int)($countryConfig['local_phone_length'] ?? 8);

        // Same gateway sign-up codes go through (register.php).
        $sendSms = static function (string $phone, string $message): bool {
            $result = CommunicationFactory::createForPhone('sms', $phone)->send($phone, $message);
            if (empty($result['success'])) {
                error_log('[ContactVerification] SMS not sent: ' . ($result['error'] ?? 'no error given'));
            }
            return (bool)($result['success'] ?? false);
        };

        return new ContactVerificationService(
            $db,
            new LoginPinVerifier(CredentialsRepository::fromEnvironment()),
            new EmailGatewayClient($config['email'] ?? []),
            $sendSms,
            $dialCode,
            $localLength
        );
    }

    /** First X-Forwarded-For hop, as the sign-in page reads it. */
    public static function clientIp(): ?string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip !== null && str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }
        return $ip;
    }
}
