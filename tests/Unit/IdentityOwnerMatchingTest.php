<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\SwapService;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Real-execution regression coverage for the identity-matching fixes
 * shipped this session: findVerifiedIdentityOwner() and
 * getPendingIdentitySwaps() both used to compare identity_value by exact
 * SQL string equality, so a phone number stored in one format never
 * matched the same number typed in a different (but equivalent) format.
 * Constructs SwapService without its DB-dependent constructor (same
 * pattern as ClaimAlgorithmV2GateTest) and swaps in a real in-memory
 * SQLite $swapDB via reflection, so these run the ACTUAL production SQL
 * + PHP normalization logic, not a re-description of it.
 */
class IdentityOwnerMatchingTest extends TestCase
{
    private PDO $db;
    private SwapService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // getPendingIdentitySwaps() uses Postgres's NOW() -- register it as
        // a SQLite UDF rather than touching the production SQL (same
        // approach TransactionAuditTrailTest uses).
        $this->db->sqliteCreateFunction('now', function () {
            return date('Y-m-d H:i:s');
        });

        $this->db->exec("
            CREATE TABLE user_identities (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                identity_type TEXT NOT NULL,
                identity_value TEXT NOT NULL,
                status TEXT NOT NULL,
                otp_pin_hash TEXT,
                otp_expires_at TEXT,
                verified INTEGER DEFAULT 0,
                verified_at TEXT,
                created_at TEXT DEFAULT (datetime('now'))
            )
        ");

        $this->db->exec("
            CREATE TABLE identity_swap_holds (
                hold_id INTEGER PRIMARY KEY AUTOINCREMENT,
                swap_reference TEXT NOT NULL,
                identity_type TEXT NOT NULL,
                identity_value TEXT NOT NULL,
                amount REAL,
                currency TEXT DEFAULT 'BWP',
                status TEXT DEFAULT 'pending',
                hold_expires_at TEXT,
                source_institution TEXT,
                source_identifier TEXT,
                metadata TEXT,
                created_at TEXT DEFAULT (datetime('now'))
            )
        ");
        $this->db->exec("
            CREATE TABLE hold_transactions (
                hold_id INTEGER PRIMARY KEY,
                status TEXT
            )
        ");

        $reflection = new \ReflectionClass(SwapService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();

        $prop = $reflection->getProperty('swapDB');
        $prop->setAccessible(true);
        $prop->setValue($this->service, $this->db);
    }

    private function findVerifiedIdentityOwner(string $type, string $value): ?array
    {
        $method = new \ReflectionMethod(SwapService::class, 'findVerifiedIdentityOwner');
        $method->setAccessible(true);
        return $method->invoke($this->service, $type, $value);
    }

    private function getPendingIdentitySwaps(string $type, string $value, string $status = 'pending'): array
    {
        $method = new \ReflectionMethod(SwapService::class, 'getPendingIdentitySwaps');
        $method->setAccessible(true);
        return $method->invoke($this->service, $type, $value, $status);
    }

    private function insertVerifiedIdentity(int $userId, string $type, string $value): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_identities (user_id, identity_type, identity_value, status, verified)
            VALUES (:uid, :type, :value, 'verified', 1)
        ");
        $stmt->execute([':uid' => $userId, ':type' => $type, ':value' => $value]);
    }

    private function insertPendingHold(string $ref, string $type, string $value, float $amount = 100.0): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO identity_swap_holds
                (swap_reference, identity_type, identity_value, amount, status, hold_expires_at, source_institution)
            VALUES (:ref, :type, :value, :amount, 'pending', datetime('now', '+1 day'), 'ZURUBANK')
        ");
        $stmt->execute([':ref' => $ref, ':type' => $type, ':value' => $value, ':amount' => $amount]);
    }

    // ------------------------------------------------------------
    // findVerifiedIdentityOwner()
    // ------------------------------------------------------------

    public function testExactMatchStillWorks(): void
    {
        $this->insertVerifiedIdentity(42, 'phone', '+26771234567');
        $owner = $this->findVerifiedIdentityOwner('phone', '+26771234567');
        $this->assertNotNull($owner);
        $this->assertSame(42, $owner['user_id']);
    }

    public function testBareLocalNumberMatchesCanonicallyStoredNumber(): void
    {
        // Registered (post-normalization-fix) in canonical form...
        $this->insertVerifiedIdentity(42, 'phone', '+26771234567');
        // ...but a sender/lookup typing the bare local number should still resolve it.
        $owner = $this->findVerifiedIdentityOwner('phone', '71234567');
        $this->assertNotNull($owner, 'bare local number must match the canonically-stored +267 form');
        $this->assertSame(42, $owner['user_id']);
    }

    public function testLegacyNonCanonicalStoredValueStillMatches(): void
    {
        // Registered BEFORE the normalization fix shipped -- stored raw, un-normalized.
        $this->insertVerifiedIdentity(7, 'phone', '71234567');
        // A fresh swap now sends the canonical form.
        $owner = $this->findVerifiedIdentityOwner('phone', '+26771234567');
        $this->assertNotNull($owner, 'legacy un-normalized registration must still be found');
        $this->assertSame(7, $owner['user_id']);
    }

    public function testUnverifiedIdentityIsNotAnOwner(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_identities (user_id, identity_type, identity_value, status, verified)
            VALUES (99, 'phone', '+26771234567', 'pending_otp', 0)
        ");
        $stmt->execute();
        $owner = $this->findVerifiedIdentityOwner('phone', '71234567');
        $this->assertNull($owner, 'an unverified identity must never be treated as a registered owner');
    }

    public function testDifferentPhoneNumberDoesNotFalsePositive(): void
    {
        $this->insertVerifiedIdentity(42, 'phone', '+26771234567');
        $owner = $this->findVerifiedIdentityOwner('phone', '+26779999999');
        $this->assertNull($owner);
    }

    public function testEmailMatchingIsCaseInsensitive(): void
    {
        $this->insertVerifiedIdentity(5, 'email', 'user@example.com');
        $owner = $this->findVerifiedIdentityOwner('email', 'User@Example.COM');
        $this->assertNotNull($owner);
        $this->assertSame(5, $owner['user_id']);
    }

    // ------------------------------------------------------------
    // getPendingIdentitySwaps() -- powers "pending claims" visibility
    // ------------------------------------------------------------

    public function testPendingSwapVisibleRegardlessOfPhoneFormatMismatch(): void
    {
        // Hold created with however the sender typed it...
        $this->insertPendingHold('SWAP_TEST_1', 'phone', '267 71 234 567');
        // ...recipient's own registered identity (or a fresh lookup) in a different format.
        $pending = $this->getPendingIdentitySwaps('phone', '+26771234567');
        $this->assertCount(1, $pending, 'a hold stored in a different but equivalent phone format must still surface as pending');
        $this->assertSame('SWAP_TEST_1', $pending[0]['swap_reference']);
    }

    public function testExpiredHoldExcludedFromPending(): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO identity_swap_holds
                (swap_reference, identity_type, identity_value, amount, status, hold_expires_at, source_institution)
            VALUES ('SWAP_EXPIRED', 'phone', '+26771234567', 50, 'pending', datetime('now', '-1 day'), 'ZURUBANK')
        ");
        $stmt->execute();
        $pending = $this->getPendingIdentitySwaps('phone', '71234567');
        $this->assertCount(0, $pending, 'an already-expired hold must not show as pending');
    }
}
