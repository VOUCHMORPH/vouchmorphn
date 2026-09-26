<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\SourceOwnershipGuard;
use Domain\Services\SourceOwnershipException;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * "I can type random characters as the account number and the hook goes
 * through." Every money-moving customer path now asks SourceOwnershipGuard
 * before anything is verified, held or debited. These run the guard's real
 * SQL against an in-memory SQLite database holding the three tables it
 * reads, and pin who may use what: only the requester's own verified
 * sources, a wallet on their own OTP-verified phone, or a voucher with its
 * PIN. Everything else - a made-up number, someone else's account, a
 * source still waiting for its ownership check - is refused with a
 * message the customer can act on.
 */
class SourceOwnershipGuardTest extends TestCase
{
    private const ME = 1;
    private const SOMEONE_ELSE = 2;

    private PDO $db;
    private SourceOwnershipGuard $guard;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE users (user_id INTEGER PRIMARY KEY, phone TEXT, phone2 TEXT, verified INTEGER)');
        $this->db->exec('
            CREATE TABLE user_source_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, institution TEXT, asset_type TEXT,
                identifier TEXT, identifier_type TEXT, status TEXT, deleted_at TEXT
            )
        ');
        // Also holds enterprise sources: an organization, no user.
        $this->db->exec('
            CREATE TABLE source_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, organization_id INTEGER, institution TEXT,
                asset_type TEXT, source_identifier TEXT, status TEXT, is_active INTEGER, deleted_at TEXT
            )
        ');

        $this->db->exec("INSERT INTO users VALUES (1, '+26771234567', '+26779999999', 1), (2, '+26772222222', NULL, 1)");
        $this->addSource(self::ME, 'ZURUBANK', 'ACCOUNT', '10000001', 'account_number', 'active');
        $this->addSource(self::SOMEONE_ELSE, 'ABSA', 'ACCOUNT', '10000002', 'account_number', 'active');

        $this->guard = new SourceOwnershipGuard($this->db, '+267', 8);
    }

    private function addSource(int $userId, string $institution, string $assetType, string $identifier, string $identifierType, string $status, ?string $deletedAt = null): void
    {
        $this->db->prepare('INSERT INTO user_source_accounts (user_id, institution, asset_type, identifier, identifier_type, status, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$userId, $institution, $assetType, $identifier, $identifierType, $status, $deletedAt]);
    }

    private function assertRefused(callable $attempt, string $expectedMessagePart): void
    {
        try {
            $attempt();
            $this->fail('Expected the source to be refused');
        } catch (SourceOwnershipException $e) {
            $this->assertStringContainsString($expectedMessagePart, $e->getMessage());
        }
    }

    // ------------------------------------------------------------
    // The reported bug, and its close relatives
    // ------------------------------------------------------------

    public function testRandomCharactersAsTheAccountNumberAreRefused(): void
    {
        $this->assertRefused(
            fn () => $this->guard->assertOwned(self::ME, ['institution' => 'ZURUBANK', 'asset_type' => 'ACCOUNT', 'identifier' => 'asdf!!qwer']),
            'is not one of your verified sources'
        );
    }

    public function testSomeoneElsesVerifiedAccountIsRefused(): void
    {
        $this->assertRefused(
            fn () => $this->guard->assertOwned(self::ME, ['institution' => 'ABSA', 'asset_type' => 'ACCOUNT', 'identifier' => '10000002']),
            'is not one of your verified sources'
        );
    }

    public function testTheSameAccountNumberAtAnotherInstitutionIsNotYours(): void
    {
        $this->assertRefused(
            fn () => $this->guard->assertOwned(self::ME, ['institution' => 'SACCUSSALIS', 'asset_type' => 'ACCOUNT', 'identifier' => '10000001']),
            'SACCUSSALIS account ending 0001'
        );
    }

    public function testYourOwnVerifiedAccountIsAcceptedHoweverItIsTyped(): void
    {
        $owned = $this->guard->assertOwned(self::ME, ['institution' => 'zurubank', 'asset_type' => 'ACCOUNT', 'identifier' => ' 1000-0001 ']);

        $this->assertSame('10000001', $owned['identifier'], 'the verified identifier is what gets sent on, not the typed one');
        $this->assertSame('ZURUBANK', $owned['institution']);
        $this->assertSame('verified_source', $owned['proof']);
    }

    public function testASourceWaitingForItsOwnershipCheckCannotBeUsedYet(): void
    {
        $this->addSource(self::ME, 'ABSA', 'ACCOUNT', '20000001', 'account_number', 'pending_confirmation');

        $this->assertRefused(
            fn () => $this->guard->assertOwned(self::ME, ['institution' => 'ABSA', 'asset_type' => 'ACCOUNT', 'identifier' => '20000001']),
            'still waiting for its ownership check'
        );
    }

    public function testARejectedSourceCannotBeUsed(): void
    {
        $this->addSource(self::ME, 'ABSA', 'ACCOUNT', '20000002', 'account_number', 'rejected');

        $this->assertRefused(
            fn () => $this->guard->assertOwned(self::ME, ['institution' => 'ABSA', 'asset_type' => 'ACCOUNT', 'identifier' => '20000002']),
            'did not pass its ownership check'
        );
    }

    public function testARemovedSourceCannotBeUsed(): void
    {
        $this->addSource(self::ME, 'ABSA', 'ACCOUNT', '20000003', 'account_number', 'active', '2026-09-01 10:00:00');

        $this->assertNull($this->guard->findOwnedSource(self::ME, 'ABSA', '20000003', 'ACCOUNT'));
    }

    public function testAnAccountNumberIsNotAlsoACard(): void
    {
        $this->assertNull($this->guard->findOwnedSource(self::ME, 'ZURUBANK', '10000001', 'CARD'));
        $this->assertNotNull($this->guard->findOwnedSource(self::ME, 'ZURUBANK', '10000001', null), 'an unknown type is not held against the match');
    }

    public function testNobodySignedInOwnsNothing(): void
    {
        $this->assertRefused(
            fn () => $this->guard->assertOwned(0, ['institution' => 'ZURUBANK', 'asset_type' => 'ACCOUNT', 'identifier' => '10000001']),
            'could not tell who is signed in'
        );
    }

    // ------------------------------------------------------------
    // Wallets and phones
    // ------------------------------------------------------------

    public function testAWalletOnYourOwnVerifiedPhoneIsYours(): void
    {
        $owned = $this->guard->assertOwned(self::ME, ['institution' => 'MTN', 'asset_type' => 'WALLET', 'identifier' => '71234567']);

        $this->assertSame('own_phone', $owned['proof']);
    }

    public function testAWalletOnSomeoneElsesPhoneIsRefused(): void
    {
        $this->assertRefused(
            fn () => $this->guard->assertOwned(self::ME, ['institution' => 'MTN', 'asset_type' => 'WALLET', 'identifier' => '+26772222222']),
            'is not one of your verified sources'
        );
    }

    public function testAnUnverifiedSecondPhoneDoesNotProveAWallet(): void
    {
        $this->assertNull($this->guard->findOwnedSource(self::ME, 'MTN', '79999999', 'WALLET'));
    }

    public function testYourPhoneNumberIsNotAlsoAnAccount(): void
    {
        $this->assertNull($this->guard->findOwnedSource(self::ME, 'ZURUBANK', '+26771234567', 'ACCOUNT'));
    }

    public function testARegisteredWalletMatchesEveryShapeOfItsNumber(): void
    {
        $this->addSource(self::ME, 'SACCUSSALIS', 'BANK-WALLET', '75555555', 'phone', 'active');

        foreach (['75555555', '+26775555555', '267 7555 5555', '075555555'] as $typed) {
            $owned = $this->guard->findOwnedSource(self::ME, 'SACCUSSALIS', $typed, 'WALLET');
            $this->assertNotNull($owned, "'{$typed}' is the registered wallet");
            $this->assertSame('75555555', $owned['identifier']);
        }
    }

    // ------------------------------------------------------------
    // OAuth-linked sources and enterprise rows
    // ------------------------------------------------------------

    public function testABankLoginLinkedSourceIsYours(): void
    {
        $this->db->exec("INSERT INTO source_accounts (user_id, institution, asset_type, source_identifier, status, is_active) VALUES (1, 'ZURUBANK', 'ACCOUNT', '30000001', 'active', 1)");

        $owned = $this->guard->findOwnedSource(self::ME, 'ZURUBANK', '30000001', 'ACCOUNT');

        $this->assertNotNull($owned);
        $this->assertSame('linked_bank_login', $owned['proof']);
    }

    public function testADeactivatedBankLoginLinkIsNotUsable(): void
    {
        $this->db->exec("INSERT INTO source_accounts (user_id, institution, asset_type, source_identifier, status, is_active) VALUES (1, 'ZURUBANK', 'ACCOUNT', '30000002', 'active', 0)");

        $this->assertNull($this->guard->findOwnedSource(self::ME, 'ZURUBANK', '30000002', 'ACCOUNT'));
    }

    public function testAnEnterpriseSourceBelongsToNoCustomer(): void
    {
        $this->db->exec("INSERT INTO source_accounts (user_id, organization_id, institution, asset_type, source_identifier, status, is_active) VALUES (NULL, 9, 'ZURUBANK', 'ACCOUNT', '40000001', 'active', 1)");

        $this->assertNull($this->guard->findOwnedSource(self::ME, 'ZURUBANK', '40000001', 'ACCOUNT'));
    }

    public function testNoSourceTablesMeansNoSources(): void
    {
        $empty = new PDO('sqlite::memory:');
        $empty->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->assertNull((new SourceOwnershipGuard($empty))->findOwnedSource(self::ME, 'ZURUBANK', '10000001', 'ACCOUNT'));
    }

    // ------------------------------------------------------------
    // Vouchers (bearer)
    // ------------------------------------------------------------

    public function testAVoucherNeedsItsPin(): void
    {
        $this->assertRefused(
            fn () => $this->guard->assertOwned(self::ME, ['institution' => 'ZURUBANK', 'asset_type' => 'VOUCHER', 'identifier' => 'VCH12345678']),
            'Enter the voucher PIN'
        );
    }

    public function testAVoucherWithItsPinIsAcceptedForTheIssuerToCheck(): void
    {
        $owned = $this->guard->assertOwned(self::ME, ['institution' => 'ZURUBANK', 'asset_type' => 'CASHOUT-VOUCHER', 'identifier' => 'VCH12345678', 'voucher_pin' => '4321']);

        $this->assertSame('voucher_pin', $owned['proof']);
    }

    public function testAMalformedVoucherNumberIsRefused(): void
    {
        $this->assertRefused(
            fn () => $this->guard->assertOwned(self::ME, ['institution' => 'ZURUBANK', 'asset_type' => 'VOUCHER', 'identifier' => '!!', 'pin' => '1234']),
            "isn't a valid voucher number"
        );
    }

    // ------------------------------------------------------------
    // Whole swap payloads
    // ------------------------------------------------------------

    public function testASingleSourceSwapIsPinnedToTheVerifiedIdentifier(): void
    {
        $secured = $this->guard->securePayload(self::ME, [
            'swap_type' => 'DEPOSIT', 'from_institution' => 'ZURUBANK', 'asset_type' => 'ACCOUNT', 'source_identifier' => '1000 0001',
        ]);

        $this->assertSame('10000001', $secured['source_identifier']);
    }

    public function testASwapFromSomeoneElsesAccountIsRefused(): void
    {
        $this->assertRefused(
            fn () => $this->guard->securePayload(self::ME, ['swap_type' => 'CASHOUT', 'from_institution' => 'ABSA', 'asset_type' => 'ACCOUNT', 'source_identifier' => '10000002']),
            'ABSA account ending 0002'
        );
    }

    public function testTheIdentifierCheckedIsTheOneSwapServiceWouldHold(): void
    {
        // extractSourceIdentifier() reads source_identifier before phone, so
        // an own phone next to someone else's account is still their account.
        $this->assertRefused(
            fn () => $this->guard->securePayload(self::ME, ['from_institution' => 'ABSA', 'asset_type' => 'ACCOUNT', 'source_identifier' => '10000002', 'phone' => '71234567']),
            'ABSA account ending 0002'
        );
    }

    public function testASourceNamedOnlyInANestedBlockIsStillChecked(): void
    {
        $this->assertRefused(
            fn () => $this->guard->securePayload(self::ME, ['source' => ['institution' => 'ABSA', 'identifier' => '10000002']]),
            'not one of your verified sources'
        );
        $this->assertRefused(
            fn () => $this->guard->securePayload(self::ME, ['bank' => 'ABSA', 'source_account' => '10000002']),
            'not one of your verified sources'
        );
    }

    public function testEveryCombinedSourceMustBeYours(): void
    {
        $this->assertRefused(
            fn () => $this->guard->securePayload(self::ME, ['swap_type' => 'MULTI_SOURCE', 'sources' => [
                ['institution' => 'ZURUBANK', 'asset_type' => 'ACCOUNT', 'identifier' => '10000001', 'amount' => 10],
                ['institution' => 'ABSA', 'asset_type' => 'ACCOUNT', 'identifier' => '10000002', 'amount' => 10],
            ]]),
            'ABSA account ending 0002'
        );
    }

    public function testCombinedSourcesArePinnedToTheirVerifiedIdentifiers(): void
    {
        $secured = $this->guard->securePayload(self::ME, ['swap_type' => 'MULTI_SOURCE', 'sources' => [
            ['institution' => 'ZURUBANK', 'asset_type' => 'ACCOUNT', 'account_id' => '1000-0001', 'amount' => 10],
            ['institution' => 'MTN', 'asset_type' => 'WALLET', 'identifier' => '+267 7123 4567', 'amount' => 5],
        ]]);

        $this->assertSame('10000001', $secured['sources'][0]['identifier']);
        $this->assertSame('10000001', $secured['sources'][0]['source_identifier']);
        $this->assertSame('+267 7123 4567', $secured['sources'][1]['source_identifier']);
    }

    public function testASwapWithNoSourceIsRefused(): void
    {
        $this->assertRefused(
            fn () => $this->guard->securePayload(self::ME, ['swap_type' => 'DEPOSIT', 'to_institution' => 'ZURUBANK', 'destination_identifier' => '10000009']),
            'Choose one of your verified sources'
        );
    }

    public function testASourceWithNoIdentifierIsRefused(): void
    {
        $this->assertRefused(
            fn () => $this->guard->securePayload(self::ME, ['swap_type' => 'DEPOSIT', 'from_institution' => 'ZURUBANK']),
            'Choose one of your verified sources at ZURUBANK'
        );
    }

    public function testInternalOverrideKeysAreStrippedAtEveryDepth(): void
    {
        $clean = SourceOwnershipGuard::withoutInternalKeys([
            'amount' => 10, '_amount' => 99999, '_is_hooked' => true,
            'original_payload' => ['_user_id' => 7, 'swap_type' => 'CONFIRM_CASHOUT'],
            'sources' => [['institution' => 'ZURUBANK', '_hold_reference' => 'HOLD_X']],
        ]);

        $this->assertSame(['amount' => 10, 'original_payload' => ['swap_type' => 'CONFIRM_CASHOUT'], 'sources' => [['institution' => 'ZURUBANK']]], $clean);
    }

    // ------------------------------------------------------------
    // Formats, checked when a source is registered
    // ------------------------------------------------------------

    /** @dataProvider validIdentifiers */
    public function testRealIdentifiersPassTheFormatCheck(string $assetType, string $identifier): void
    {
        SourceOwnershipGuard::assertIdentifierFormat($assetType, $identifier);
        $this->addToAssertionCount(1);
    }

    public static function validIdentifiers(): array
    {
        return [
            'account' => ['ACCOUNT', '10000001'],
            'spaced account' => ['ACCOUNT', '6200 1234 567'],
            'wallet, local' => ['WALLET', '71234567'],
            'wallet, e164' => ['BANK-WALLET', '+267 71 234 567'],
            'visa test card' => ['CARD', '4111 1111 1111 1111'],
            'voucher' => ['VOUCHER', 'VCH12345678'],
        ];
    }

    /** @dataProvider invalidIdentifiers */
    public function testRandomCharactersFailTheFormatCheck(string $assetType, string $identifier): void
    {
        $this->expectException(SourceOwnershipException::class);
        SourceOwnershipGuard::assertIdentifierFormat($assetType, $identifier);
    }

    public static function invalidIdentifiers(): array
    {
        return [
            'symbols' => ['ACCOUNT', 'asdf!!'],
            'letters only' => ['ACCOUNT', 'ABCDEFGHIJ'],
            'too short' => ['ACCOUNT', '1234'],
            'too long' => ['ACCOUNT', '12345678901234567890'],
            'wallet that is not a phone' => ['WALLET', 'my wallet'],
            'wallet too short' => ['WALLET', '7123'],
            'card failing luhn' => ['CARD', '4111111111111112'],
            'card too short' => ['CARD', '411111'],
            'voucher with symbols' => ['VOUCHER', '<script>'],
        ];
    }

    // ------------------------------------------------------------
    // Phone identity (USSD) and country rules
    // ------------------------------------------------------------

    public function testACallersPhoneResolvesToTheOneVerifiedUserWhoOwnsIt(): void
    {
        $this->assertSame(self::ME, $this->guard->userIdForPhone('+26771234567'));
        $this->assertSame(self::ME, $this->guard->userIdForPhone('071234567'));
        $this->assertNull($this->guard->userIdForPhone('79999999'), 'phone2 is not a verified phone');
        $this->assertNull($this->guard->userIdForPhone('70000000'), 'nobody has this number');
    }

    public function testAnAmbiguousOrUnverifiedPhoneResolvesToNobody(): void
    {
        $this->db->exec("INSERT INTO users VALUES (3, '26773333333', NULL, 1), (4, '+26773333333', NULL, 1), (5, '+26774444444', NULL, 0)");

        $this->assertNull($this->guard->userIdForPhone('73333333'), 'two users hold this number');
        $this->assertNull($this->guard->userIdForPhone('74444444'), 'an unverified user is nobody');
    }

    public function testPhoneRulesComeFromTheCountry(): void
    {
        $this->assertSame(['+267', 8], SourceOwnershipGuard::phoneRules(['country' => 'Botswana', 'country_code' => 'BW']));
        $this->assertSame('+27', SourceOwnershipGuard::phoneRules(['country' => 'South Africa'])[0]);
        $this->assertSame(['+254', 9], SourceOwnershipGuard::phoneRules([
            'country' => 'KE', 'country_settings' => ['KE' => ['dial_code' => '+254', 'local_phone_length' => 9]],
        ]));
    }
}
