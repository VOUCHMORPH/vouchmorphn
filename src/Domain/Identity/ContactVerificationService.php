<?php
declare(strict_types=1);

namespace Domain\Identity;

use Infrastructure\Email\Contracts\EmailProviderInterface;
use PDO;
use PDOException;
use Security\Auth\LoginPinVerifier;

/**
 * Lets a signed-in user add or change the email or phone number on their
 * own account, proving they control it with a 6-digit code.
 *
 * Phone sign-ups use it to add an email (the dashboard banner until they
 * do); email sign-ups use it to add a phone. What it writes is what
 * sign-in reads: users.email + users.email_verified_at, or users.phone.
 *
 *   start()   checks the account's login PIN (with the sign-in lockout —
 *             this is the one step that stops someone at an unlocked phone
 *             from attaching their own email), checks the address or
 *             number is not on another account, and sends a code to it.
 *   confirm() checks the code and writes the contact to the account, then
 *             tells the account's other contacts it happened.
 *
 * Codes live in otp_logs like the sign-up codes do — hash only, 10
 * minutes, 5 wrong tries — under the purpose sign-up already uses
 * ('verification'), so this adds nothing the live CHECK constraint on
 * otp_logs could refuse. The code is tied to the request that asked for it
 * by its otp_id, which the caller keeps in the session between the steps.
 */
final class ContactVerificationService
{
    public const TYPE_EMAIL = 'email';
    public const TYPE_PHONE = 'phone';

    public const CODE_TTL_SECONDS = 600;
    public const MAX_CODE_ATTEMPTS = 5;
    public const RESEND_COOLDOWN_SECONDS = 60;

    private const OTP_PURPOSE = 'verification';

    private PDO $db;
    private LoginPinVerifier $pinVerifier;
    private EmailProviderInterface $email;
    /** @var callable(string, string): bool */
    private $sendSms;
    private string $dialCode;
    private int $localLength;

    /**
     * @param callable(string $phone, string $message): bool $sendSms
     */
    public function __construct(
        PDO $db,
        LoginPinVerifier $pinVerifier,
        EmailProviderInterface $email,
        callable $sendSms,
        string $dialCode = '+267',
        int $localLength = 8
    ) {
        $this->db = $db;
        $this->pinVerifier = $pinVerifier;
        $this->email = $email;
        $this->sendSms = $sendSms;
        $this->dialCode = '+' . ltrim($dialCode, '+');
        $this->localLength = $localLength;
    }

    /**
     * Step 1: check the PIN and send a code to $value.
     *
     * @param array<string, mixed>|null $previous the pending step from an earlier start(), for the resend wait
     * @param array{ip?: ?string, user_agent?: ?string} $client
     * @return array{pending: array<string, mixed>, masked: string, message: string}
     */
    public function start(int $userId, string $type, string $value, string $pin, ?array $previous = null, array $client = []): array
    {
        $type = strtolower(trim($type));
        if ($type !== self::TYPE_EMAIL && $type !== self::TYPE_PHONE) {
            throw new ContactVerificationException('Choose email or phone.');
        }
        $value = $this->normalize($type, $value);

        if ($previous !== null && (int) ($previous['user_id'] ?? 0) === $userId) {
            $wait = (int) ($previous['sent_at'] ?? 0) + self::RESEND_COOLDOWN_SECONDS - time();
            if ($wait > 0) {
                throw new ContactVerificationException("Please wait {$wait} seconds before asking for another code.");
            }
        }

        if ($type === self::TYPE_EMAIL && !SignInSchema::hasEmailVerifiedAt($this->db)) {
            // Nowhere to record the verification yet — see
            // database/migrations/2026_09_27_email_sign_in.sql.
            throw new ContactVerificationException("Adding an email isn't available just yet. Please try again later.");
        }

        $this->checkPin($userId, $pin);

        $user = $this->loadUser($userId);
        if ($type === self::TYPE_EMAIL
            && IdentifierNormalizer::canonicalEmail((string) ($user['email'] ?? '')) === $value
            && AccountEmail::isVerified($user, true)) {
            throw new ContactVerificationException('This email is already verified on your account.');
        }
        if ($type === self::TYPE_PHONE && ($user['phone'] ?? null) === $value) {
            throw new ContactVerificationException('This number is already on your account.');
        }
        $this->assertNotOnAnotherAccount($userId, $type, $value);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpId = $this->storeCode($type, $value, $code, $client);

        $sent = $type === self::TYPE_EMAIL
            ? $this->emailCode($value, $code)
            : $this->smsCode($value, $code);
        if (!$sent) {
            $this->markUsed($otpId);
            throw new ContactVerificationException($type === self::TYPE_EMAIL
                ? "We couldn't send the code to that email. Please check it and try again."
                : "We couldn't text the code to that number. Please check it and try again.");
        }

        $masked = $type === self::TYPE_EMAIL ? AccountEmail::mask($value) : self::maskPhone($value);

        return [
            'pending' => [
                'user_id' => $userId,
                'type' => $type,
                'value' => $value,
                'otp_id' => $otpId,
                'sent_at' => time(),
                'expires_at' => time() + self::CODE_TTL_SECONDS,
            ],
            'masked' => $masked,
            'message' => "We sent a 6-digit code to {$masked}. It expires in " . intdiv(self::CODE_TTL_SECONDS, 60) . ' minutes.',
        ];
    }

    /**
     * Step 2: check the code and save the contact on the account.
     *
     * @param array<string, mixed>|null $pending what start() returned under 'pending'
     * @return array{type: string, value: string, message: string}
     */
    public function confirm(int $userId, ?array $pending, string $code): array
    {
        if ($pending === null || (int) ($pending['user_id'] ?? 0) !== $userId || empty($pending['otp_id'])) {
            throw new ContactVerificationException('Please ask for a new code.');
        }
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            throw new ContactVerificationException('Please enter the 6-digit code.');
        }

        $type = (string) $pending['type'];
        $value = (string) $pending['value'];

        $stmt = $this->db->prepare(
            'SELECT otp_id, identifier, code_hash, expires_at, used_at, attempts FROM otp_logs WHERE otp_id = :id'
        );
        $stmt->execute([':id' => (int) $pending['otp_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['identifier'] !== $value || $row['used_at'] !== null) {
            throw new ContactVerificationException('That code is no longer valid. Please ask for a new one.');
        }
        $expiresAt = strtotime((string) $row['expires_at']);
        if (time() > (int) ($pending['expires_at'] ?? 0) || $expiresAt === false || $expiresAt < time()) {
            throw new ContactVerificationException('That code has expired. Please ask for a new one.');
        }
        if ((int) $row['attempts'] >= self::MAX_CODE_ATTEMPTS) {
            throw new ContactVerificationException('Too many wrong codes. Please ask for a new one.');
        }

        if (!password_verify($code, (string) $row['code_hash'])) {
            $this->db->prepare('UPDATE otp_logs SET attempts = attempts + 1 WHERE otp_id = :id')
                ->execute([':id' => (int) $row['otp_id']]);
            $left = self::MAX_CODE_ATTEMPTS - ((int) $row['attempts'] + 1);
            throw new ContactVerificationException($left > 0
                ? "That code isn't right. You have {$left} " . ($left === 1 ? 'try' : 'tries') . ' left.'
                : 'Too many wrong codes. Please ask for a new one.');
        }

        // Only one request can use the code, even two at once.
        $use = $this->db->prepare('UPDATE otp_logs SET used_at = NOW() WHERE otp_id = :id AND used_at IS NULL');
        $use->execute([':id' => (int) $row['otp_id']]);
        if ($use->rowCount() !== 1) {
            throw new ContactVerificationException('That code is no longer valid. Please ask for a new one.');
        }

        $before = $this->loadUser($userId);
        $this->assertNotOnAnotherAccount($userId, $type, $value);

        try {
            if ($type === self::TYPE_EMAIL) {
                $this->db->prepare('UPDATE users SET email = :email, email_verified_at = NOW() WHERE user_id = :id')
                    ->execute([':email' => $value, ':id' => $userId]);
            } else {
                $this->db->prepare('UPDATE users SET phone = :phone WHERE user_id = :id')
                    ->execute([':phone' => $value, ':id' => $userId]);
            }
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23505') {
                throw new ContactVerificationException($this->takenMessage($type));
            }
            throw $e;
        }

        $this->alertOtherContacts($before, $type, $value);

        return [
            'type' => $type,
            'value' => $value,
            'message' => $type === self::TYPE_EMAIL
                ? 'Your email is verified. You can now use it to sign in.'
                : 'Your phone number is saved. You can now use it to sign in.',
        ];
    }

    // ------------------------------------------------------------------

    private function normalize(string $type, string $value): string
    {
        if ($type === self::TYPE_EMAIL) {
            $email = IdentifierNormalizer::canonicalEmail($value);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new ContactVerificationException('Please enter a valid email address.');
            }
            if (AccountEmail::isPlaceholder($email)) {
                throw new ContactVerificationException('Please use your own email address.');
            }
            return $email;
        }

        $phone = IdentifierNormalizer::canonicalPhone($value, $this->dialCode, $this->localLength);
        $pattern = '/^' . preg_quote($this->dialCode, '/') . '[0-9]{' . $this->localLength . '}$/';
        if (!preg_match($pattern, $phone)) {
            throw new ContactVerificationException("Please enter a valid {$this->localLength}-digit phone number.");
        }
        return $phone;
    }

    private function checkPin(int $userId, string $pin): void
    {
        if (!preg_match('/^\d{4,6}$/', trim($pin))) {
            throw new ContactVerificationException('Please enter your PIN.');
        }
        $check = $this->pinVerifier->check($userId, trim($pin));
        if ($check['result'] === LoginPinVerifier::LOCKED) {
            throw new ContactVerificationException(
                'Too many wrong PINs. For your safety, this is paused for ' . LoginPinVerifier::LOCK_MINUTES . ' minutes.'
            );
        }
        if ($check['result'] !== LoginPinVerifier::OK) {
            throw new ContactVerificationException("That PIN isn't right.");
        }
    }

    /** @return array<string, mixed> */
    private function loadUser(int $userId): array
    {
        $columns = 'user_id, email, phone' . (SignInSchema::hasEmailVerifiedAt($this->db) ? ', email_verified_at' : '');
        $stmt = $this->db->prepare("SELECT {$columns} FROM users WHERE user_id = :id");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new ContactVerificationException('Your account could not be found. Please sign in again.');
        }
        return $user;
    }

    /**
     * Any other account holding this address (verified or not — the
     * UNIQUE (email) constraint doesn't care) or this number in any of
     * its phone columns, in any stored shape.
     */
    private function assertNotOnAnotherAccount(int $userId, string $type, string $value): void
    {
        if ($type === self::TYPE_EMAIL) {
            $stmt = $this->db->prepare('SELECT user_id FROM users WHERE lower(email) = :email AND user_id <> :me LIMIT 1');
            $stmt->execute([':email' => $value, ':me' => $userId]);
        } else {
            $variants = IdentifierNormalizer::phoneVariants($value, $this->dialCode, $this->localLength);
            $params = [':me' => $userId];
            $clauses = [];
            foreach (['phone', 'phone2', 'phone3'] as $c => $column) {
                if (!SignInSchema::hasColumn($this->db, 'users', $column)) {
                    continue;
                }
                $names = [];
                foreach ($variants as $i => $variant) {
                    $names[] = ":p{$c}_{$i}";
                    $params[":p{$c}_{$i}"] = $variant;
                }
                $clauses[] = "{$column} IN (" . implode(', ', $names) . ')';
            }
            $stmt = $this->db->prepare(
                'SELECT user_id FROM users WHERE (' . implode(' OR ', $clauses) . ') AND user_id <> :me LIMIT 1'
            );
            $stmt->execute($params);
        }

        if ($stmt->fetchColumn()) {
            throw new ContactVerificationException($this->takenMessage($type));
        }
    }

    private function takenMessage(string $type): string
    {
        return $type === self::TYPE_EMAIL
            ? 'This email is already linked to another VouchMorph account.'
            : 'This number is already linked to another VouchMorph account.';
    }

    /** @param array{ip?: ?string, user_agent?: ?string} $client */
    private function storeCode(string $type, string $value, string $code, array $client): int
    {
        // An older unused code for this address stops working the moment a
        // new one is sent — including one from a sign-up in progress.
        $this->db->prepare('UPDATE otp_logs SET used_at = NOW() WHERE identifier = :identifier AND used_at IS NULL')
            ->execute([':identifier' => $value]);

        $ip = $client['ip'] ?? null;
        $stmt = $this->db->prepare("
            INSERT INTO otp_logs
                (identifier, identifier_type, code_hash, purpose, expires_at, attempts, ip_address, user_agent, created_at)
            VALUES
                (:identifier, :identifier_type, :code_hash, :purpose, :expires_at, 0, :ip, :user_agent, NOW())
        ");
        $stmt->execute([
            ':identifier' => $value,
            ':identifier_type' => $type,
            ':code_hash' => password_hash($code, PASSWORD_DEFAULT),
            ':purpose' => self::OTP_PURPOSE,
            // With its UTC offset, so it means the same instant whatever
            // timezone the database session runs in.
            ':expires_at' => date(DATE_ATOM, time() + self::CODE_TTL_SECONDS),
            // otp_logs.ip_address is inet in the canonical schema: a
            // non-IP string would make the whole insert fail.
            ':ip' => ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP)) ? $ip : null,
            ':user_agent' => isset($client['user_agent']) ? substr((string) $client['user_agent'], 0, 500) : null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function markUsed(int $otpId): void
    {
        $this->db->prepare('UPDATE otp_logs SET used_at = NOW() WHERE otp_id = :id')->execute([':id' => $otpId]);
    }

    private function emailCode(string $to, string $code): bool
    {
        $minutes = intdiv(self::CODE_TTL_SECONDS, 60);
        $body = "
            <html>
            <body style='font-family: Arial, sans-serif;'>
                <h2>Verify your email</h2>
                <p>Use this code to add this email to your VouchMorph account:</p>
                <p><strong style='font-size: 24px; color: #00636e;'>{$code}</strong></p>
                <p>This code expires in {$minutes} minutes.</p>
                <p><strong>Never share this code with anyone, including VouchMorph staff.</strong></p>
                <p>If you didn't ask for this, you can ignore this email.</p>
                <hr>
                <small>VouchMorph</small>
            </body>
            </html>
        ";
        $result = $this->email->sendEmail($to, 'Your VouchMorph verification code', $body);

        return (bool) ($result['success'] ?? false);
    }

    private function smsCode(string $phone, string $code): bool
    {
        $minutes = intdiv(self::CODE_TTL_SECONDS, 60);

        return $this->sms($phone, "Your VouchMorph code to add this number to your account: {$code}. It expires in {$minutes} minutes. Never share it.");
    }

    private function sms(string $phone, string $message): bool
    {
        try {
            return (bool) ($this->sendSms)($phone, $message);
        } catch (\Throwable $e) {
            error_log('[ContactVerificationService] SMS failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Tell the contacts the account already had that a new one was added,
     * so a change the owner didn't make doesn't go unnoticed. Best effort:
     * a failed alert never undoes the change.
     *
     * @param array<string, mixed> $before the users row before the change
     */
    private function alertOtherContacts(array $before, string $type, string $value): void
    {
        $what = $type === self::TYPE_EMAIL
            ? 'the email ' . AccountEmail::mask($value)
            : 'the phone number ' . self::maskPhone($value);
        $text = "VouchMorph: {$what} was added to your account. If this wasn't you, contact VouchMorph support right away.";

        $oldPhone = trim((string) ($before['phone'] ?? ''));
        if ($oldPhone !== '' && !($type === self::TYPE_PHONE && $oldPhone === $value)) {
            if (!$this->sms($oldPhone, $text)) {
                error_log("[ContactVerificationService] Could not send the new-{$type} alert by SMS for user_id={$before['user_id']}");
            }
        }

        $oldEmail = IdentifierNormalizer::canonicalEmail((string) ($before['email'] ?? ''));
        if (AccountEmail::isVerified($before, SignInSchema::hasEmailVerifiedAt($this->db))
            && !($type === self::TYPE_EMAIL && $oldEmail === $value)) {
            try {
                $this->email->sendEmail(
                    $oldEmail,
                    "A new {$type} was added to your VouchMorph account",
                    '<html><body style="font-family: Arial, sans-serif;"><p>' . htmlspecialchars($text) . '</p></body></html>'
                );
            } catch (\Throwable $e) {
                error_log("[ContactVerificationService] Could not send the new-{$type} alert by email: " . $e->getMessage());
            }
        }
    }

    /** "+26771234567" -> "+267•••••567" */
    public static function maskPhone(string $phone): string
    {
        $len = strlen($phone);
        if ($len <= 7) {
            return str_repeat('•', $len);
        }

        return substr($phone, 0, 4) . str_repeat('•', $len - 7) . substr($phone, -3);
    }
}
