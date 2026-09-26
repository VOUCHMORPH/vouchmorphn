<?php

use PHPUnit\Framework\TestCase;
use Domain\Identity\ContactVerificationException;
use Domain\Identity\ContactVerificationService;
use Domain\Identity\SignInSchema;
use Infrastructure\Credentials\CredentialsRepository;
use Infrastructure\Email\Contracts\EmailProviderInterface;
use Security\Auth\LoginPinVerifier;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Adding an email (phone sign-ups) or a phone number (email sign-ups) from
 * the dashboard: PIN first, then a code to the new address or number, then
 * it is saved on the account and the account's other contacts are told.
 *
 * Real service SQL on in-memory SQLite for the main database, the real
 * credentials schema for the PIN, and fake email/SMS senders that record
 * what would have been sent.
 */
class ContactVerificationServiceTest extends TestCase
{
    private const PIN = '482913';

    private const PHONE_USER = 1;   // signed up with a phone: made-up email
    private const EMAIL_USER = 2;   // signed up with an email: no phone
    private const OTHER_USER = 3;

    private PDO $db;
    private ContactVerificationService $service;
    private ContactVerificationTestMailer $mailer;
    /** @var array<int, array{0: string, 1: string}> */
    private array $texts = [];
    private bool $smsWorks = true;

    protected function setUp(): void
    {
        SignInSchema::forget();
        $this->db = self::sqlite();
        $this->db->exec('
            CREATE TABLE users (
                user_id INTEGER PRIMARY KEY,
                username TEXT NOT NULL,
                email TEXT NOT NULL,
                phone TEXT,
                phone2 TEXT,
                phone3 TEXT,
                email_verified_at TEXT
            )
        ');
        $this->db->exec('
            CREATE TABLE otp_logs (
                otp_id INTEGER PRIMARY KEY AUTOINCREMENT,
                identifier TEXT NOT NULL,
                identifier_type TEXT,
                code_hash TEXT NOT NULL,
                purpose TEXT,
                expires_at TEXT NOT NULL,
                used_at TEXT,
                attempts INTEGER DEFAULT 0,
                ip_address TEXT,
                user_agent TEXT,
                created_at TEXT
            )
        ');
        $this->db->exec("
            INSERT INTO users (user_id, username, email, phone, phone2, email_verified_at) VALUES
                (1, 'thabo', 'thabo@botswana.vouchmorphn.com', '+26771234567', NULL, NULL),
                (2, 'jane',  'jane@example.com',               NULL,           NULL, '2026-09-01T10:00:00+02:00'),
                (3, 'other', 'taken@example.com',              '+26772000000', '+26773000000', '2026-09-01T10:00:00+02:00')
        ");

        $credDb = self::sqlite();
        $credDb->exec(file_get_contents(__DIR__ . '/../../scripts/credentials_db/schema.sql'));
        $credentials = new CredentialsRepository($credDb);
        foreach ([self::PHONE_USER, self::EMAIL_USER, self::OTHER_USER] as $userId) {
            $credentials->createUserCredential($userId, password_hash(self::PIN, PASSWORD_BCRYPT, ['cost' => 4]));
        }

        $this->mailer = new ContactVerificationTestMailer();
        $this->service = new ContactVerificationService(
            $this->db,
            new LoginPinVerifier($credentials),
            $this->mailer,
            function (string $phone, string $message): bool {
                $this->texts[] = [$phone, $message];
                return $this->smsWorks;
            }
        );
    }

    public function testPhoneUserVerifiesAnEmail(): void
    {
        $started = $this->service->start(self::PHONE_USER, 'email', ' Thabo@Example.com ', self::PIN);

        $this->assertSame('thabo@example.com', $started['pending']['value']);
        $this->assertSame('t••••@example.com', $started['masked']);
        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('thabo@example.com', $this->mailer->sent[0]['to']);

        $done = $this->service->confirm(self::PHONE_USER, $started['pending'], $this->emailedCode());

        $this->assertSame('email', $done['type']);
        $user = $this->user(self::PHONE_USER);
        $this->assertSame('thabo@example.com', $user['email'], 'the made-up address is replaced');
        $this->assertNotNull($user['email_verified_at']);

        // The phone the account already had is told.
        $this->assertCount(1, $this->texts);
        $this->assertSame('+26771234567', $this->texts[0][0]);
        $this->assertStringContainsString('t••••@example.com', $this->texts[0][1]);
    }

    public function testEmailUserAddsAPhone(): void
    {
        $started = $this->service->start(self::EMAIL_USER, 'phone', '71 234 568', self::PIN);

        $this->assertSame('+26771234568', $started['pending']['value']);
        $this->assertCount(1, $this->texts);
        $this->assertSame('+26771234568', $this->texts[0][0]);

        $this->service->confirm(self::EMAIL_USER, $started['pending'], $this->textedCode());

        $this->assertSame('+26771234568', $this->user(self::EMAIL_USER)['phone']);
        // The verified email the account already had is told.
        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame('jane@example.com', $this->mailer->sent[0]['to']);
    }

    public function testWrongPinSendsNothing(): void
    {
        $this->assertRefused("That PIN isn't right.", fn() => $this->service->start(self::PHONE_USER, 'email', 'thabo@example.com', '000000'));

        $this->assertSame([], $this->mailer->sent);
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM otp_logs')->fetchColumn());
    }

    public function testAnEmailOnAnotherAccountIsRefused(): void
    {
        $this->assertRefused(
            'This email is already linked to another VouchMorph account.',
            fn() => $this->service->start(self::PHONE_USER, 'email', 'Taken@Example.com', self::PIN)
        );
        $this->assertSame([], $this->mailer->sent);
    }

    public function testANumberOnAnotherAccountIsRefusedInAnyPhoneColumn(): void
    {
        $this->assertRefused(
            'This number is already linked to another VouchMorph account.',
            fn() => $this->service->start(self::EMAIL_USER, 'phone', '73000000', self::PIN)
        );
    }

    public function testAnAlreadyVerifiedEmailIsNotSentAgain(): void
    {
        $this->assertRefused(
            'This email is already verified on your account.',
            fn() => $this->service->start(self::EMAIL_USER, 'email', 'jane@example.com', self::PIN)
        );
    }

    public function testBadInputIsRefusedBeforeThePinIsChecked(): void
    {
        $this->assertRefused('Please enter a valid email address.', fn() => $this->service->start(self::PHONE_USER, 'email', 'not-an-email', '000000'));
        $this->assertRefused('Please use your own email address.', fn() => $this->service->start(self::PHONE_USER, 'email', 'x@botswana.vouchmorphn.com', '000000'));
        $this->assertRefused('Please enter a valid 8-digit phone number.', fn() => $this->service->start(self::EMAIL_USER, 'phone', '123', '000000'));
        $this->assertRefused('Choose email or phone.', fn() => $this->service->start(self::EMAIL_USER, 'fax', '123', '000000'));
    }

    public function testWrongCodesCountDownAndThenStopWorking(): void
    {
        $started = $this->service->start(self::PHONE_USER, 'email', 'thabo@example.com', self::PIN);
        $code = $this->emailedCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->assertRefused("That code isn't right. You have 4 tries left.", fn() => $this->service->confirm(self::PHONE_USER, $started['pending'], $wrong));
        $this->assertRefused("That code isn't right. You have 3 tries left.", fn() => $this->service->confirm(self::PHONE_USER, $started['pending'], $wrong));
        $this->assertRefused("That code isn't right. You have 2 tries left.", fn() => $this->service->confirm(self::PHONE_USER, $started['pending'], $wrong));
        $this->assertRefused("That code isn't right. You have 1 try left.", fn() => $this->service->confirm(self::PHONE_USER, $started['pending'], $wrong));
        $this->assertRefused('Too many wrong codes. Please ask for a new one.', fn() => $this->service->confirm(self::PHONE_USER, $started['pending'], $wrong));

        // Even the right code is refused now.
        $this->assertRefused('Too many wrong codes. Please ask for a new one.', fn() => $this->service->confirm(self::PHONE_USER, $started['pending'], $code));
        $this->assertNull($this->user(self::PHONE_USER)['email_verified_at']);
    }

    public function testAnExpiredCodeIsRefused(): void
    {
        $started = $this->service->start(self::PHONE_USER, 'email', 'thabo@example.com', self::PIN);
        $pending = $started['pending'];
        $pending['expires_at'] = time() - 1;

        $this->assertRefused('That code has expired. Please ask for a new one.', fn() => $this->service->confirm(self::PHONE_USER, $pending, $this->emailedCode()));
    }

    public function testACodeWorksOnlyOnce(): void
    {
        $started = $this->service->start(self::PHONE_USER, 'email', 'thabo@example.com', self::PIN);
        $code = $this->emailedCode();
        $this->service->confirm(self::PHONE_USER, $started['pending'], $code);

        $this->assertRefused('That code is no longer valid. Please ask for a new one.', fn() => $this->service->confirm(self::PHONE_USER, $started['pending'], $code));
    }

    public function testAnotherUsersPendingStepIsRefused(): void
    {
        $started = $this->service->start(self::PHONE_USER, 'email', 'thabo@example.com', self::PIN);

        $this->assertRefused('Please ask for a new code.', fn() => $this->service->confirm(self::OTHER_USER, $started['pending'], $this->emailedCode()));
    }

    public function testASecondCodeMustWaitAMinute(): void
    {
        $first = $this->service->start(self::PHONE_USER, 'email', 'thabo@example.com', self::PIN);

        try {
            $this->service->start(self::PHONE_USER, 'email', 'thabo@example.com', self::PIN, $first['pending']);
            $this->fail('A second code within a minute should be refused');
        } catch (ContactVerificationException $e) {
            $this->assertStringStartsWith('Please wait', $e->getMessage());
        }
        $this->assertCount(1, $this->mailer->sent);
    }

    public function testANewCodeReplacesTheOldOne(): void
    {
        $first = $this->service->start(self::PHONE_USER, 'email', 'thabo@example.com', self::PIN);
        $firstCode = $this->emailedCode();
        $first['pending']['sent_at'] = time() - 120;
        $second = $this->service->start(self::PHONE_USER, 'email', 'thabo@example.com', self::PIN, $first['pending']);

        $this->assertRefused('That code is no longer valid. Please ask for a new one.', fn() => $this->service->confirm(self::PHONE_USER, $first['pending'], $firstCode));
        $this->service->confirm(self::PHONE_USER, $second['pending'], $this->emailedCode());
        $this->assertNotNull($this->user(self::PHONE_USER)['email_verified_at']);
    }

    public function testAFailedSendCannotBeCompleted(): void
    {
        $this->smsWorks = false;

        $this->assertRefused(
            "We couldn't text the code to that number. Please check it and try again.",
            fn() => $this->service->start(self::EMAIL_USER, 'phone', '71234568', self::PIN)
        );
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM otp_logs WHERE used_at IS NULL')->fetchColumn());
    }

    public function testEmailWaitsForTheMigration(): void
    {
        SignInSchema::forget();
        $this->db->exec('CREATE TABLE users_new (user_id INTEGER PRIMARY KEY, username TEXT NOT NULL, email TEXT NOT NULL, phone TEXT, phone2 TEXT, phone3 TEXT)');
        $this->db->exec('INSERT INTO users_new SELECT user_id, username, email, phone, phone2, phone3 FROM users');
        $this->db->exec('DROP TABLE users');
        $this->db->exec('ALTER TABLE users_new RENAME TO users');

        $this->assertRefused(
            "Adding an email isn't available just yet. Please try again later.",
            fn() => $this->service->start(self::PHONE_USER, 'email', 'thabo@example.com', self::PIN)
        );
    }

    // ------------------------------------------------------------------

    private function assertRefused(string $message, callable $step): void
    {
        try {
            $step();
        } catch (ContactVerificationException $e) {
            $this->assertSame($message, $e->getMessage());
            return;
        }
        $this->fail("Expected: {$message}");
    }

    private function emailedCode(): string
    {
        $last = end($this->mailer->sent);
        $this->assertNotFalse($last, 'no email was sent');
        $this->assertSame(1, preg_match('/<strong[^>]*>(\d{6})<\/strong>/', $last['body'], $m));
        return $m[1];
    }

    private function textedCode(): string
    {
        $last = end($this->texts);
        $this->assertNotFalse($last, 'no text was sent');
        $this->assertSame(1, preg_match('/: (\d{6})\./', $last[1], $m));
        return $m[1];
    }

    /** @return array<string, mixed> */
    private function user(int $userId): array
    {
        return $this->db->query("SELECT * FROM users WHERE user_id = {$userId}")->fetch();
    }

    private static function sqlite(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->sqliteCreateFunction('now', fn() => date('Y-m-d H:i:s'));
        return $db;
    }
}

final class ContactVerificationTestMailer implements EmailProviderInterface
{
    /** @var array<int, array{to: string, subject: string, body: string}> */
    public array $sent = [];

    public function sendEmail(string $to, string $subject, string $htmlBody): array
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $htmlBody];
        return ['success' => true, 'message' => 'Email sent'];
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
