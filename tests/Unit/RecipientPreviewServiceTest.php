<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\RecipientPreviewService;
use Infrastructure\Adapters\InstitutionAdapterFactory;
use Infrastructure\Adapters\InstitutionAdapterInterface;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Stands in for GenericInstitutionAdapter so the name enquiry can be driven
 * from the test without a live bank. InstitutionAdapterFactory picks the
 * adapter class out of the participant config's adapter_class field, so
 * pointing a fake participant at this class substitutes it in with no change
 * to production code (same trick as TransactionAuditTrailTest's FakeDebitAdapter).
 */
class NameEnquiryFakeAdapter implements InstitutionAdapterInterface
{
    /** @var array<string, array> canned verifyAccount response per institution */
    public static array $verifyAccountResponses = [];
    /** @var array<string, array> every payload verifyAccount was called with */
    public static array $verifyAccountCalls = [];
    /** @var array<string, string> institutions whose name enquiry throws */
    public static array $throwFor = [];

    public static function reset(): void
    {
        self::$verifyAccountResponses = [];
        self::$verifyAccountCalls = [];
        self::$throwFor = [];
    }

    private string $institution;

    public function __construct($bankClient, $logger, string $institution, array $config)
    {
        $this->institution = $institution;
    }

    public function verifyAccount(array $payload, array $context): array
    {
        self::$verifyAccountCalls[$this->institution][] = $payload;

        if (isset(self::$throwFor[$this->institution])) {
            throw new RuntimeException(self::$throwFor[$this->institution]);
        }

        return self::$verifyAccountResponses[$this->institution]
            ?? ['verified' => false, 'success' => false, 'message' => 'no fake response configured'];
    }

    public function verifyAsset(array $payload, array $context): array { return ['verified' => false, 'success' => false]; }
    public function placeHold(array $payload, array $context): array { return ['hold_placed' => false, 'success' => false]; }
    public function releaseHold(array $payload, array $context): array { return ['released' => false, 'success' => false]; }
    public function debit(array $payload, array $context): array { return ['debited' => false, 'success' => false]; }
    public function credit(array $payload, array $context): array { return ['credited' => false, 'success' => false]; }
    public function generateCashoutToken(array $payload, array $context): array { return ['success' => false]; }
    public function verifyCashoutToken(array $payload, array $context): array { return ['verified' => false]; }
    public function confirmCashout(array $payload, array $context): array { return ['confirmed' => false]; }
    public function getBalance(array $payload, array $context): array { return ['success' => false, 'balance' => 0]; }
    public function getTransactions(array $payload, array $context): array { return ['success' => false, 'transactions' => []]; }
    public function checkSettlementStatus(array $payload, array $context): array { return ['success' => false, 'settled' => false]; }
    public function getAccounts(array $payload, array $context): array { return ['success' => false, 'accounts' => []]; }
    public function createReservationAccount(array $payload, array $context): array { return ['success' => false]; }
    public function getReservationAccountStatus(array $payload, array $context): array { return ['success' => false]; }
    public function supports(string $capability): bool { return true; }
    public function getInstitution(): string { return $this->institution; }
}

/**
 * The recipient name shown on the review screen, before the sender confirms.
 *
 * Two things are being protected at once and both are asserted here: the
 * sender must see enough to catch a mistyped account or ID number, and the
 * full name of whoever owns that account must never reach the browser.
 */
class RecipientPreviewServiceTest extends TestCase
{
    private const INSTITUTION = 'ZURUBANK';

    private PDO $db;

    protected function setUp(): void
    {
        NameEnquiryFakeAdapter::reset();

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec("
            CREATE TABLE users (
                user_id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT,
                full_name TEXT
            )
        ");

        $this->db->exec("
            CREATE TABLE user_identities (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                identity_type TEXT NOT NULL,
                identity_value TEXT NOT NULL,
                status TEXT NOT NULL
            )
        ");
    }

    private function makeService(): RecipientPreviewService
    {
        $participants = [
            self::INSTITUTION => [
                'provider_code' => self::INSTITUTION,
                'adapter_class' => NameEnquiryFakeAdapter::class,
            ],
        ];

        $logger = new class {
            public function __call($name, $args) {}
        };

        return new RecipientPreviewService($this->db, new InstitutionAdapterFactory($participants, $logger));
    }

    private function addUser(string $username, ?string $fullName): int
    {
        $stmt = $this->db->prepare("INSERT INTO users (username, full_name) VALUES (?, ?)");
        $stmt->execute([$username, $fullName]);

        return (int)$this->db->lastInsertId();
    }

    private function addIdentity(int $userId, string $type, string $value, string $status = 'verified'): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_identities (user_id, identity_type, identity_value, status)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $type, $value, $status]);
    }

    // ------------------------------------------------------------
    // Paying an account at an institution
    // ------------------------------------------------------------

    public function testAccountHolderNameComesBackMaskedNeverInFull(): void
    {
        NameEnquiryFakeAdapter::$verifyAccountResponses[self::INSTITUTION] = [
            'verified' => true,
            'success' => true,
            'account_name' => 'John Michael Doe',
            'account_number' => '1234567890',
        ];

        $result = $this->makeService()->previewAccountRecipient(self::INSTITUTION, '1234567890');

        $this->assertSame(RecipientPreviewService::STATUS_RESOLVED, $result['status']);
        $this->assertTrue($result['resolved']);
        $this->assertSame('J*** M****** D**', $result['name_masked']);
        $this->assertTrue($result['identifier_confirmed']);

        $encoded = json_encode($result);
        $this->assertStringNotContainsString('John', $encoded, 'the unmasked name must never leave the service');
        $this->assertStringNotContainsString('Michael', $encoded);
        $this->assertStringNotContainsString('Doe', $encoded);
    }

    public function testNameEnquiryAsksTheInstitutionAboutTheIdentifierTheSenderTyped(): void
    {
        NameEnquiryFakeAdapter::$verifyAccountResponses[self::INSTITUTION] = [
            'verified' => true,
            'success' => true,
            'account_name' => 'Ada Lovelace',
        ];

        $this->makeService()->previewAccountRecipient(self::INSTITUTION, '555000111', 'account', 'ACCOUNT', 'ABSA');

        $payload = NameEnquiryFakeAdapter::$verifyAccountCalls[self::INSTITUTION][0];

        $this->assertSame('VERIFY_ACCOUNT', $payload['action']);
        $this->assertSame('555000111', $payload['account_identifier']);
        $this->assertSame('account', $payload['identifier_type']);
        $this->assertSame(self::INSTITUTION, $payload['destination_institution']);
        $this->assertSame('ABSA', $payload['source_institution']);
    }

    public function testAccountNumberFormattingDifferencesStillCountAsTheSameAccount(): void
    {
        // Banks echo the same number back spaced and dashed however they
        // please; that is not a reason to warn the sender off.
        NameEnquiryFakeAdapter::$verifyAccountResponses[self::INSTITUTION] = [
            'verified' => true,
            'success' => true,
            'account_name' => 'Ada Lovelace',
            'account_number' => '1234-5678-90',
        ];

        $result = $this->makeService()->previewAccountRecipient(self::INSTITUTION, '1234 5678 90');

        $this->assertSame(RecipientPreviewService::STATUS_RESOLVED, $result['status']);
        $this->assertTrue($result['identifier_confirmed']);
    }

    public function testAnswerAboutADifferentAccountShowsNoNameAtAll(): void
    {
        // The dangerous case: a name IS available, but it belongs to an
        // account other than the one the sender typed. Showing it would be
        // worse than showing nothing -- it reads as confirmation.
        NameEnquiryFakeAdapter::$verifyAccountResponses[self::INSTITUTION] = [
            'verified' => true,
            'success' => true,
            'account_name' => 'Grace Hopper',
            'account_number' => '9999999999',
        ];

        $result = $this->makeService()->previewAccountRecipient(self::INSTITUTION, '1234567890');

        $this->assertSame(RecipientPreviewService::STATUS_MISMATCH, $result['status']);
        $this->assertFalse($result['resolved']);
        $this->assertNull($result['name_masked']);
        $this->assertFalse($result['identifier_confirmed']);
        $this->assertStringNotContainsString('Hopper', json_encode($result));
    }

    public function testUnverifiedAccountIsReportedAsUnverifiableRatherThanNamed(): void
    {
        NameEnquiryFakeAdapter::$verifyAccountResponses[self::INSTITUTION] = [
            'verified' => false,
            'success' => false,
            'message' => 'Account not found',
        ];

        $result = $this->makeService()->previewAccountRecipient(self::INSTITUTION, '1234567890');

        $this->assertSame(RecipientPreviewService::STATUS_UNVERIFIABLE, $result['status']);
        $this->assertNull($result['name_masked']);
    }

    public function testAnUnreachableInstitutionFailsSoftInsteadOfBlowingUpTheReviewScreen(): void
    {
        NameEnquiryFakeAdapter::$throwFor[self::INSTITUTION] = 'connection timed out';

        $result = $this->makeService()->previewAccountRecipient(self::INSTITUTION, '1234567890');

        $this->assertSame(RecipientPreviewService::STATUS_UNVERIFIABLE, $result['status']);
        $this->assertNull($result['name_masked']);
        $this->assertNotSame('', $result['message']);
    }

    public function testUnknownInstitutionIsReportedRatherThanThrown(): void
    {
        // InstitutionAdapterFactory throws for a participant it has never
        // heard of; the review screen must survive that.
        $result = $this->makeService()->previewAccountRecipient('NOT_A_PARTICIPANT', '1234567890');

        $this->assertSame(RecipientPreviewService::STATUS_UNVERIFIABLE, $result['status']);
        $this->assertNull($result['name_masked']);
    }

    public function testVerifiedAccountWithAnUnpreviewableNameSaysSoRatherThanShowingIt(): void
    {
        // A single word fails Privacy Level Validation: there is no first and
        // last name to mask, so nothing is shown.
        NameEnquiryFakeAdapter::$verifyAccountResponses[self::INSTITUTION] = [
            'verified' => true,
            'success' => true,
            'account_name' => 'Madonna',
        ];

        $result = $this->makeService()->previewAccountRecipient(self::INSTITUTION, '1234567890');

        $this->assertSame(RecipientPreviewService::STATUS_NAME_UNAVAILABLE, $result['status']);
        $this->assertNull($result['name_masked']);
        $this->assertStringNotContainsString('Madonna', json_encode($result));
    }

    public function testMissingInstitutionOrIdentifierNeverReachesTheBank(): void
    {
        $result = $this->makeService()->previewAccountRecipient(self::INSTITUTION, '   ');

        $this->assertSame(RecipientPreviewService::STATUS_UNVERIFIABLE, $result['status']);
        $this->assertSame([], NameEnquiryFakeAdapter::$verifyAccountCalls);
    }

    // ------------------------------------------------------------
    // Paying an identity (national ID, phone, email, ...)
    // ------------------------------------------------------------

    public function testIdentityOwnerNameComesBackMasked(): void
    {
        $userId = $this->addUser('mthatayaone', 'Marvin Thatayaone Sehunelo');
        $this->addIdentity($userId, 'national_id', '123456789');

        $result = $this->makeService()->previewIdentityRecipient('national_id', '123456789');

        $this->assertSame(RecipientPreviewService::STATUS_RESOLVED, $result['status']);
        $this->assertSame('M***** T********* S*******', $result['name_masked']);
        $this->assertStringNotContainsString('Marvin', json_encode($result));
    }

    public function testIdentityMatchesEvenWhenTheSenderTypesItInADifferentFormat(): void
    {
        // Registered bare and local, typed international: same person. This
        // is the same normalisation the swap itself applies, so a name shown
        // here is a name the swap will also resolve.
        $userId = $this->addUser('ada', 'Ada Lovelace');
        $this->addIdentity($userId, 'phone', '71234567');

        $result = $this->makeService()->previewIdentityRecipient('phone', '+267 71234567');

        $this->assertSame(RecipientPreviewService::STATUS_RESOLVED, $result['status']);
        $this->assertSame('A** L*******', $result['name_masked']);
    }

    public function testDashedNationalIdMatchesTheUndashedRegisteredValue(): void
    {
        $userId = $this->addUser('grace', 'Grace Brewster Hopper');
        $this->addIdentity($userId, 'national_id', '000000001234');

        $result = $this->makeService()->previewIdentityRecipient('national_id', '0000 - 0000 - 1234');

        $this->assertSame(RecipientPreviewService::STATUS_RESOLVED, $result['status']);
        $this->assertSame('G**** B******* H*****', $result['name_masked']);
    }

    public function testUnregisteredIdentityExplainsWhatHappensInsteadOfLookingLikeAnError(): void
    {
        $result = $this->makeService()->previewIdentityRecipient('national_id', '123456789');

        $this->assertSame(RecipientPreviewService::STATUS_NOT_REGISTERED, $result['status']);
        $this->assertFalse($result['resolved']);
        $this->assertNull($result['name_masked']);
        $this->assertStringContainsString('agent', $result['message']);
    }

    public function testIdentityStillAwaitingVerificationCountsAsUnregistered(): void
    {
        // Only a verified identity decides who can claim the money, so only a
        // verified identity may put a name on the review screen.
        $userId = $this->addUser('pending', 'Pending Person');
        $this->addIdentity($userId, 'national_id', '123456789', 'pending_review');

        $result = $this->makeService()->previewIdentityRecipient('national_id', '123456789');

        $this->assertSame(RecipientPreviewService::STATUS_NOT_REGISTERED, $result['status']);
        $this->assertStringNotContainsString('Pending', json_encode($result));
    }

    public function testAccountWithNoFullNameOnFileNeverFallsBackToTheUsername(): void
    {
        // Usernames here are generated at registration from a phone number or
        // an email address. Showing one in place of a name would put a
        // stranger's contact details on screen.
        $userId = $this->addUser('26771234567', null);
        $this->addIdentity($userId, 'national_id', '123456789');

        $result = $this->makeService()->previewIdentityRecipient('national_id', '123456789');

        $this->assertSame(RecipientPreviewService::STATUS_NAME_UNAVAILABLE, $result['status']);
        $this->assertNull($result['name_masked']);
        $this->assertStringNotContainsString('26771234567', json_encode($result));
    }

    public function testBlankIdentityValueIsRejectedWithoutQueryingAnything(): void
    {
        $result = $this->makeService()->previewIdentityRecipient('national_id', '  ');

        $this->assertSame(RecipientPreviewService::STATUS_UNVERIFIABLE, $result['status']);
        $this->assertNull($result['name_masked']);
    }

    // ------------------------------------------------------------
    // Cashing out to someone at an ATM
    // ------------------------------------------------------------

    public function testCashoutNamesTheOwnerOfThePhoneTheCodeGoesTo(): void
    {
        $userId = $this->addUser('ada', 'Ada Lovelace');
        $this->addIdentity($userId, 'phone', '+26771234567');

        $result = $this->makeService()->previewCashoutRecipient('71234567');

        $this->assertSame(RecipientPreviewService::STATUS_RESOLVED, $result['status']);
        $this->assertSame('A** L*******', $result['name_masked']);
        $this->assertSame('CASHOUT', $result['destination_type']);
    }

    public function testCashoutToAPhoneWithNoAccountReadsAsNormalNotAsAProblem(): void
    {
        $result = $this->makeService()->previewCashoutRecipient('71234567');

        $this->assertSame(RecipientPreviewService::STATUS_NOT_REGISTERED, $result['status']);
        $this->assertSame('CASHOUT', $result['destination_type']);
        $this->assertStringContainsString('code is texted', $result['message']);
    }

    public function testCashoutNeverAsksTheDestinationBankAboutThePhone(): void
    {
        // A bank asked for a name against a phone that is not its customer
        // answers "no such account" on nearly every cashout. A warning that
        // fires every time is a warning nobody reads.
        $this->makeService()->previewCashoutRecipient('71234567');

        $this->assertSame([], NameEnquiryFakeAdapter::$verifyAccountCalls);
    }

    public function testAnIdentityOfADifferentTypeWithTheSameValueIsNotTheSameRecipient(): void
    {
        $userId = $this->addUser('voter', 'Voter Person');
        $this->addIdentity($userId, 'voter_id', '123456789');

        $result = $this->makeService()->previewIdentityRecipient('national_id', '123456789');

        $this->assertSame(RecipientPreviewService::STATUS_NOT_REGISTERED, $result['status']);
    }
}
