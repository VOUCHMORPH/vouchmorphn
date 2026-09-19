<?php
declare(strict_types=1);

namespace Domain\Services;

use Infrastructure\Adapters\InstitutionAdapterFactory;
use PDO;
use PDOException;
use Throwable;

/**
 * "Who is actually getting this money?" — answered on the review screen,
 * before anything moves.
 *
 * The sender types an account number or an identity document number, both of
 * which are easy to get one digit wrong. Until now the only place the
 * recipient's name was ever looked up was inside the swap itself
 * (SwapService::verifyAccount, VERIFY_ACCOUNT step), which runs AFTER the
 * sender has already confirmed — far too late to catch a typo. This service
 * performs the same lookup as a read-only preview so the name can be shown
 * while the swap can still be abandoned.
 *
 * Three kinds of destination, one answer shape:
 *   - an institution account / wallet / card -> name enquiry at that
 *     institution, via the same adapter the swap would use;
 *   - a VouchMorph identity (national ID, phone, email, ...) -> the verified
 *     owner of that identity, which is the same lookup that decides at
 *     execution time whether the recipient can claim instantly;
 *   - a cashout -> the owner of the phone the code is texted to, since that
 *     is who collects the cash.
 *
 * The name never leaves this service unmasked. PrivacyMasker turns
 * "John Michael Doe" into "J*** M****** D**": the real John recognises it
 * instantly, and someone typing in account numbers to see who owns them
 * learns nothing they did not already have. That trade-off is the reason a
 * preview like this is safe to expose at all, so it is enforced here rather
 * than left to each caller.
 */
final class RecipientPreviewService
{
    /** A masked name is available for display. */
    public const STATUS_RESOLVED = 'RESOLVED';

    /** Nobody has verified this identity with VouchMorph yet. */
    public const STATUS_NOT_REGISTERED = 'NOT_REGISTERED';

    /** Recipient exists, but there is no name on file good enough to preview. */
    public const STATUS_NAME_UNAVAILABLE = 'NAME_UNAVAILABLE';

    /** The institution could not be asked, or refused to answer. */
    public const STATUS_UNVERIFIABLE = 'UNVERIFIABLE';

    /** The institution answered about a different account than the one asked about. */
    public const STATUS_MISMATCH = 'MISMATCH';

    private PDO $db;
    private InstitutionAdapterFactory $adapterFactory;

    public function __construct(PDO $db, InstitutionAdapterFactory $adapterFactory)
    {
        $this->db = $db;
        $this->adapterFactory = $adapterFactory;
    }

    /**
     * Name enquiry against the destination institution.
     *
     * @return array{
     *     status: string, resolved: bool, name_masked: ?string,
     *     destination_type: string, institution: ?string,
     *     identifier_confirmed: ?bool, message: string
     * }
     */
    public function previewAccountRecipient(
        string $institution,
        string $identifier,
        string $identifierType = 'account',
        string $assetType = 'ACCOUNT',
        ?string $sourceInstitution = null
    ): array {
        $institution = trim($institution);
        $identifier = trim($identifier);

        if ($institution === '' || $identifier === '') {
            return $this->accountAnswer(
                self::STATUS_UNVERIFIABLE,
                null,
                $institution !== '' ? $institution : null,
                null,
                'Choose a destination institution and account before we can check the name.'
            );
        }

        $reference = 'NAME_PREVIEW_' . bin2hex(random_bytes(6));

        try {
            $adapter = $this->adapterFactory->getAdapter($institution);

            $result = $adapter->verifyAccount([
                'action' => 'VERIFY_ACCOUNT',
                'reference' => $reference,
                'account_identifier' => $identifier,
                'identifier_type' => $identifierType,
                'requester' => 'VOUCHMORPH',
                'timestamp' => time(),
                'from_institution' => $sourceInstitution,
                'source_institution' => $sourceInstitution,
                'to_institution' => $institution,
                'destination_institution' => $institution,
                'destination_asset_type' => $assetType,
            ], [
                'swap_reference' => $reference,
                'source_institution' => $sourceInstitution,
                'destination_institution' => $institution,
                'destination_identifier' => ['identifier' => $identifier, 'type' => $identifierType],
                'destination_asset_type' => $assetType,
                'signed_payloads' => [],
            ]);
        } catch (Throwable $e) {
            error_log("[RecipientPreviewService] Name enquiry failed for {$institution}: " . $e->getMessage());

            return $this->accountAnswer(
                self::STATUS_UNVERIFIABLE,
                null,
                $institution,
                null,
                "We couldn't reach {$institution} to confirm the name. Check the account number yourself before confirming."
            );
        }

        if (!($result['verified'] ?? false)) {
            return $this->accountAnswer(
                self::STATUS_UNVERIFIABLE,
                null,
                $institution,
                null,
                "{$institution} couldn't confirm that account. Check the number before confirming."
            );
        }

        // The account the institution answered about must be the account we
        // asked about — compared on letters and digits only, because the same
        // number comes back spaced and dashed differently from bank to bank.
        // Same check SwapService::verifyAccount() applies at execution time;
        // it belongs here too, or the preview would cheerfully show a name
        // belonging to an account that the swap is about to reject.
        $returnedNumber = $result['account_number'] ?? null;
        $identifierConfirmed = null;

        if ($returnedNumber !== null && $returnedNumber !== '') {
            $identifierConfirmed = $this->sameIdentifier((string)$returnedNumber, $identifier);

            if (!$identifierConfirmed) {
                return $this->accountAnswer(
                    self::STATUS_MISMATCH,
                    null,
                    $institution,
                    false,
                    "{$institution} answered about a different account than the one you entered. Check the number before confirming."
                );
            }
        }

        $plv = PrivacyMasker::maskHolderName($result['account_name'] ?? null);

        if (!$plv['valid']) {
            return $this->accountAnswer(
                self::STATUS_NAME_UNAVAILABLE,
                null,
                $institution,
                $identifierConfirmed,
                "The account exists at {$institution}, but it didn't return a name we can show you."
            );
        }

        return $this->accountAnswer(
            self::STATUS_RESOLVED,
            $plv['masked'],
            $institution,
            $identifierConfirmed,
            "This account at {$institution} belongs to {$plv['masked']}."
        );
    }

    /**
     * Owner of a VouchMorph identity (national ID, phone, email, ...).
     *
     * Deliberately the same rule as SwapService::findVerifiedIdentityOwner():
     * identity_type filtered in SQL, identity_value compared normalised in
     * PHP. An identity that matches here is exactly an identity whose owner
     * will be able to claim the money from their own dashboard.
     *
     * @return array{
     *     status: string, resolved: bool, name_masked: ?string,
     *     destination_type: string, institution: ?string,
     *     identifier_confirmed: ?bool, message: string
     * }
     */
    public function previewIdentityRecipient(string $identityType, string $identityValue): array
    {
        $identityType = strtolower(trim($identityType));
        $identityValue = trim($identityValue);

        if ($identityType === '' || $identityValue === '') {
            return $this->identityAnswer(
                self::STATUS_UNVERIFIABLE,
                null,
                'Enter the recipient\'s identity number before we can check the name.'
            );
        }

        try {
            $target = SwapService::normalizeIdentityValue($identityType, $identityValue);

            $stmt = $this->db->prepare("
                SELECT ui.identity_value, u.full_name
                FROM user_identities ui
                JOIN users u ON u.user_id = ui.user_id
                WHERE ui.identity_type = :type AND ui.status = 'verified'
            ");
            $stmt->execute([':type' => $identityType]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("[RecipientPreviewService] Identity owner lookup failed: " . $e->getMessage());

            return $this->identityAnswer(
                self::STATUS_UNVERIFIABLE,
                null,
                "We couldn't check who owns that identity right now. Double-check the number before confirming."
            );
        }

        $owner = null;
        foreach ($rows as $row) {
            if (SwapService::normalizeIdentityValue($identityType, (string)$row['identity_value']) === $target) {
                $owner = $row;
                break;
            }
        }

        if ($owner === null) {
            return $this->identityAnswer(
                self::STATUS_NOT_REGISTERED,
                null,
                'Nobody has verified this identity with VouchMorph yet. The money is held for them, and an agent checks their ID before paying out.'
            );
        }

        $plv = PrivacyMasker::maskHolderName($owner['full_name'] ?? null);

        // No fallback to username on purpose: usernames here are generated at
        // registration from a phone number or email, so showing one would put
        // a stranger's contact details on screen while looking like a name.
        if (!$plv['valid']) {
            return $this->identityAnswer(
                self::STATUS_NAME_UNAVAILABLE,
                null,
                'This identity belongs to a VouchMorph account, but that account has no full name on file to show you.'
            );
        }

        return $this->identityAnswer(
            self::STATUS_RESOLVED,
            $plv['masked'],
            "This identity belongs to {$plv['masked']}."
        );
    }

    /**
     * Cashout pays whoever is standing at the ATM holding the code, and the
     * code goes to a phone number — so the question is "whose phone is this?",
     * not "whose account is this?". Asking the destination bank for a name
     * against a phone that is very often not their customer would answer
     * "couldn't confirm that account" on nearly every cashout, and a warning
     * that fires every time is a warning nobody reads. The phone registry is
     * the right place to ask, and not finding the number there is normal
     * rather than suspicious.
     *
     * @return array{
     *     status: string, resolved: bool, name_masked: ?string,
     *     destination_type: string, institution: ?string,
     *     identifier_confirmed: ?bool, message: string
     * }
     */
    public function previewCashoutRecipient(string $beneficiaryPhone): array
    {
        $answer = $this->previewIdentityRecipient('phone', $beneficiaryPhone);
        $answer['destination_type'] = 'CASHOUT';

        if ($answer['status'] === self::STATUS_NOT_REGISTERED) {
            $answer['message'] = 'This number has no VouchMorph account, so there is no name to check against. The cashout code is texted to it — make sure it is the right number.';
        }

        if ($answer['status'] === self::STATUS_UNVERIFIABLE && trim($beneficiaryPhone) === '') {
            $answer['message'] = 'Enter the phone number the cashout code should go to.';
        }

        return $answer;
    }

    private function sameIdentifier(string $a, string $b): bool
    {
        $normalise = static fn(string $v): string => strtolower(preg_replace('/[^A-Za-z0-9]/', '', $v) ?? '');

        return $normalise($a) === $normalise($b);
    }

    private function accountAnswer(
        string $status,
        ?string $maskedName,
        ?string $institution,
        ?bool $identifierConfirmed,
        string $message
    ): array {
        return [
            'status' => $status,
            'resolved' => $status === self::STATUS_RESOLVED,
            'name_masked' => $maskedName,
            'destination_type' => 'ACCOUNT',
            'institution' => $institution,
            'identifier_confirmed' => $identifierConfirmed,
            'message' => $message,
        ];
    }

    private function identityAnswer(string $status, ?string $maskedName, string $message): array
    {
        return [
            'status' => $status,
            'resolved' => $status === self::STATUS_RESOLVED,
            'name_masked' => $maskedName,
            'destination_type' => 'IDENTITY',
            'institution' => null,
            'identifier_confirmed' => null,
            'message' => $message,
        ];
    }
}
