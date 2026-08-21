<?php
declare(strict_types=1);

namespace Infrastructure\Iso8583;

use Domain\Services\CardService;
use Domain\Services\SwapService;
use Infrastructure\Hsm\PinVerificationInterface;
use PDO;

/**
 * Bridges real ISO 8583 messages (0100/0200/0400) into the SAME
 * authorization/finalize/reversal logic authorize.php and CardService
 * already use over REST — no duplicated business rules, this is
 * purely a protocol adapter.
 *
 * PROCESSING CODE AWARE (field 3): distinguishes ATM cash withdrawal
 * ('01'-prefixed) from POS purchase ('00'-prefixed) per ISO 8583
 * convention, and resolves the real destination institution from
 * field 32 rather than trusting a free-text acquirer label — see
 * SwapService::resolveInstitutionByAcquirerId() for the (currently
 * placeholder) resolution logic.
 *
 * PIN HANDLING: field 52 (PIN block) is routed to a real
 * PinVerificationInterface implementation — an HSM, never decrypted
 * or compared in this class or anywhere else in application code. See
 * Infrastructure\Hsm\PinVerificationInterface's header for why.
 *
 * The TOTP dynamic_code path (CardService::authorizePooledSwipe()'s
 * existing check) remains the verification method for the app/QR
 * channel authorize.php serves — NOT used for real ISO 8583 terminal
 * traffic, which always carries a PIN block instead.
 */
class Iso8583AuthorizationBridge
{
    private PDO $db;
    private CardService $cardService;
    private SwapService $swapService;
    private PinVerificationInterface $pinVerifier;

    public function __construct(
        PDO $db,
        CardService $cardService,
        SwapService $swapService,
        PinVerificationInterface $pinVerifier
    ) {
        $this->db = $db;
        $this->cardService = $cardService;
        $this->swapService = $swapService;
        $this->pinVerifier = $pinVerifier;
    }

    /**
     * Takes a raw wire-format ISO 8583 message, routes it through
     * existing CardService logic, and returns a raw wire-format
     * response message ready to send back to the terminal/switch.
     */
    public function handle(string $rawRequest): string
    {
        try {
            $request = Iso8583Message::fromWire($rawRequest);
        } catch (\Throwable $e) {
            // Can't even parse the request — no STAN/field data available
            // to build a proper response. Log and return a bare-minimum
            // format-error response; a real deployment should confirm
            // with its switch/sponsor what they expect here specifically,
            // since this case is itself outside normal certification flow.
            error_log("[Iso8583AuthorizationBridge] Failed to parse inbound message: " . $e->getMessage());
            return $this->buildFormatErrorResponse();
        }

        return match ($request->mti) {
            '0100' => $this->handleAuthorizationRequest($request, '0110')->toWire(),
            '0200' => $this->handleFinancialRequest($request, '0210')->toWire(),
            '0400' => $this->handleReversalRequest($request, '0420')->toWire(),
            default => $this->buildUnsupportedMtiResponse($request)->toWire(),
        };
    }

    /**
     * Field 3 (processing code) — first two digits determine
     * transaction type per ISO 8583 convention. '01' = cash
     * withdrawal (ATM), '00' = purchase (POS). This determines both
     * how the destination institution is expected to behave
     * (dispense cash vs. credit a merchant) and which
     * CardService finalize path applies.
     */
    private function classifyProcessingCode(?string $processingCode): string
    {
        $prefix = substr((string)$processingCode, 0, 2);
        return match ($prefix) {
            '01' => 'ATM_CASH',
            '00' => 'POS_PURCHASE',
            default => 'UNKNOWN',
        };
    }

    /**
     * 0100 — pure authorization request (funds availability check, no
     * financial posting yet — e.g. an ATM's initial "can this card
     * withdraw this much" check before dispensing).
     */
    private function handleAuthorizationRequest(Iso8583Message $request, string $responseMti): Iso8583Message
    {
        $pan = $request->fields[2] ?? null;
        $amountMinorUnits = $request->fields[4] ?? '000000000000';
        $dynamicCode = $request->fields[52] ?? null; // see class header note #1

        if (!$pan) {
            return $this->buildDeclineResponse($request, $responseMti, '30'); // '30' = format error
        }

        $amount = ((float)$amountMinorUnits) / 100; // ISO 8583 amounts are minor units (cents/thebe)
        $cardSuffix = $this->resolveCardSuffixFromPan($pan);

        if (!$cardSuffix) {
            return $this->buildDeclineResponse($request, $responseMti, '14'); // '14' = invalid card number
        }

        // Same underlying check authorize.php's pooled-hook branch uses —
        // NOT calling authorizePooledSwipe() itself here, since 0100 is
        // explicitly non-financial (no debit/settlement should follow
        // from this message alone, only from a subsequent 0200 or a
        // 0100/0120 completion pair, depending on network rules).
        $hasFunds = $this->checkHookedFundsAvailable($cardSuffix, $amount);

        if (!$hasFunds) {
            return $this->buildDeclineResponse($request, $responseMti, '51'); // '51' = insufficient funds
        }

        return $this->buildApprovedResponse($request, $responseMti, $this->generateAuthCode());
    }

    /**
     * 0200 — financial transaction request. This DOES move money —
     * routes into CardService::authorizePooledSwipe(), with PIN
     * verification going through the HSM interface (never TOTP, for
     * real terminal traffic) and the destination institution resolved
     * from field 32 rather than trusted verbatim from the wire.
     */
    private function handleFinancialRequest(Iso8583Message $request, string $responseMti): Iso8583Message
    {
        $pan = $request->fields[2] ?? null;
        $amountMinorUnits = $request->fields[4] ?? '000000000000';
        $pinBlock = $request->fields[52] ?? null;
        $acquirerId = $request->fields[32] ?? null;
        $merchantId = $request->fields[42] ?? null;
        $terminalId = $request->fields[41] ?? null;
        $stan = $request->fields[11] ?? null;
        $processingCode = $request->fields[3] ?? null;

        if (!$pan) {
            return $this->buildDeclineResponse($request, $responseMti, '30');
        }

        $amount = ((float)$amountMinorUnits) / 100;
        $cardSuffix = $this->resolveCardSuffixFromPan($pan);

        if (!$cardSuffix) {
            return $this->buildDeclineResponse($request, $responseMti, '14');
        }

        // PIN verification — real HSM only, see class header.
        if (!$pinBlock) {
            return $this->buildDeclineResponse($request, $responseMti, '55'); // '55' = incorrect PIN (none supplied)
        }
        $keyZoneId = $acquirerId ?? 'DEFAULT';
        if (!$this->pinVerifier->verifyPin($pinBlock, $pan, $keyZoneId)) {
            return $this->buildDeclineResponse($request, $responseMti, '55');
        }

        $transactionType = $this->classifyProcessingCode($processingCode);
        if ($transactionType === 'UNKNOWN') {
            return $this->buildDeclineResponse($request, $responseMti, '12'); // invalid transaction
        }

        // Resolve the REAL destination institution — never trust field 32
        // as-is without confirming it against a known participant. See
        // SwapService::resolveInstitutionByAcquirerId()'s header note:
        // this mapping is currently a placeholder pending real
        // acquirer-ID data.
        $destinationInstitution = $acquirerId ? $this->swapService->resolveInstitutionByAcquirerId($acquirerId) : null;
        if (!$destinationInstitution) {
            error_log("[Iso8583AuthorizationBridge] Could not resolve acquirer ID '{$acquirerId}' to a known institution — declining rather than settling against an unverified counterparty");
            return $this->buildDeclineResponse($request, $responseMti, '91'); // '91' = issuer or switch inoperative (closest fit — acquirer unrecognized)
        }

        $merchantContext = [
            'merchant_reference' => $stan,
            'merchant_id' => $merchantId,
            'terminal_id' => $terminalId,
            'channel' => $transactionType === 'ATM_CASH' ? 'ISO8583_ATM' : 'ISO8583_POS',
            'acquirer' => $acquirerId,
            'resolved_destination_institution' => $destinationInstitution,
            // NOTE: no 'dynamic_code' here — this is a real terminal
            // request, verified via PIN/HSM above, not the app/QR TOTP
            // path. CardService::authorizePooledSwipe() still requires
            // SOME truthy dynamic_code value per its current signature —
            // pass a sentinel marking this as HSM-verified, and see the
            // CardService patch note below on updating that method to
            // accept an already-verified flag instead of re-checking TOTP.
            'pin_verified_via_hsm' => true,
        ];

        $result = $this->cardService->authorizePooledSwipe($cardSuffix, $amount, $merchantContext);

        if (!($result['authorized'] ?? false)) {
            $responseCode = match ($result['response_code'] ?? '96') {
                '51' => '51', // insufficient funds
                default => '05', // generic decline
            };
            return $this->buildDeclineResponse($request, $responseMti, $responseCode);
        }

        try {
            $queueStmt = $this->db->prepare("
                INSERT INTO card_pool_finalize_queue (hook_reference, merchant_context, status)
                VALUES (?, ?::jsonb, 'PENDING')
            ");
            $queueStmt->execute([$result['hook_reference'], json_encode($merchantContext)]);
        } catch (\Throwable $e) {
            error_log("[Iso8583AuthorizationBridge] Failed to queue finalize for hook {$result['hook_reference']}: " . $e->getMessage());
        }

        return $this->buildApprovedResponse($request, $responseMti, $result['auth_code'] ?? $this->generateAuthCode());
    }

    /**
     * 0400 — reversal request. Routes into
     * CardService::reversePooledSwipe(), which itself refuses to
     * silently unwind an already-settled transaction — see that
     * method's header for the manual-reconciliation fallback.
     */
    private function handleReversalRequest(Iso8583Message $request, string $responseMti): Iso8583Message
    {
        // Field 90 (original data elements) carries the original
        // transaction's MTI/STAN/date — used here to look up which
        // hook_reference the original 0200 corresponded to. This
        // requires the finalize queue / hook record to be searchable by
        // STAN, which isn't guaranteed by the schema shown so far —
        // flagging as a real integration point to confirm, not assuming
        // it works as written.
        $originalData = $request->fields[90] ?? null;
        $originalStan = $originalData ? substr($originalData, 4, 6) : ($request->fields[11] ?? null);

        if (!$originalStan) {
            return $this->buildDeclineResponse($request, $responseMti, '30');
        }

        $stmt = $this->db->prepare("
            SELECT hook_reference FROM card_pool_finalize_queue
            WHERE merchant_context->>'merchant_reference' = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$originalStan]);
        $hookReference = $stmt->fetchColumn();

        if (!$hookReference) {
            // Also check already-finalized hooks directly, in case the
            // queue entry was already consumed/cleared by the worker.
            $stmt2 = $this->db->prepare("
                SELECT hook_reference FROM card_pool_hooks
                WHERE merchant_reference = ? ORDER BY id DESC LIMIT 1
            ");
            $stmt2->execute([$originalStan]);
            $hookReference = $stmt2->fetchColumn();
        }

        if (!$hookReference) {
            return $this->buildDeclineResponse($request, $responseMti, '25'); // '25' = no original transaction found
        }

        $result = $this->cardService->reversePooledSwipe((string)$hookReference, 'ISO8583 reversal (MTI 0400), STAN=' . $originalStan);

        $fields = $this->baseResponseFields($request);
        $fields[39] = ($result['success'] ?? false) ? '00' : '05';
        return Iso8583Message::build($responseMti, $fields);
    }

    /**
     * Resolves a PAN to card_suffix. Mirrors authorize.php's existing
     * HMAC-SHA256 PAN hashing (see CardService::hashPan()) — never
     * compares/stores raw PAN.
     */
    private function resolveCardSuffixFromPan(string $pan): ?string
    {
        // Uses CardService's own hashPan() indirectly via a lookup method
        // that would need to be exposed publicly — hashPan() is currently
        // private. Add a small public wrapper:
        //
        //   public function resolveCardSuffixByPan(string $pan): ?string {
        //       $hash = $this->hashPan($pan);
        //       $stmt = $this->db->prepare("SELECT card_suffix FROM message_cards WHERE card_number_hash = ?");
        //       $stmt->execute([$hash]);
        //       return $stmt->fetchColumn() ?: null;
        //   }
        //
        // Calling that here once added:
        return $this->cardService->resolveCardSuffixByPan($pan);
    }

    private function checkHookedFundsAvailable(string $cardSuffix, float $amount): bool
    {
        $stmt = $this->db->prepare("
            SELECT total_held_amount FROM card_pool_hooks
            WHERE card_suffix = ? AND status = 'HOOKED' AND expires_at > NOW()
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$cardSuffix]);
        $held = $stmt->fetchColumn();
        return $held !== false && (float)$held >= $amount;
    }

    private function generateAuthCode(): string
    {
        return strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    private function buildApprovedResponse(Iso8583Message $request, string $responseMti, string $authCode): Iso8583Message
    {
        $fields = $this->baseResponseFields($request);
        $fields[38] = $authCode; // Authorization ID response — note: add 38 to FIELD_SPECS (['an','fixed:6']) before this ships
        $fields[39] = '00'; // '00' = approved
        return Iso8583Message::build($responseMti, $fields);
    }

    private function buildDeclineResponse(Iso8583Message $request, string $responseMti, string $responseCode): Iso8583Message
    {
        $fields = $this->baseResponseFields($request);
        $fields[39] = $responseCode;
        return Iso8583Message::build($responseMti, $fields);
    }

    private function buildUnsupportedMtiResponse(Iso8583Message $request): Iso8583Message
    {
        // '12' = invalid transaction (MTI not supported by this host)
        $responseMti = substr($request->mti, 0, 3) . '1'; // best-effort — real MTI response mapping is spec-defined per request MTI, confirm per network rules
        $fields = $this->baseResponseFields($request);
        $fields[39] = '12';
        return Iso8583Message::build($responseMti, $fields);
    }

    private function buildFormatErrorResponse(): string
    {
        // Cannot echo back fields from a message that failed to parse —
        // this is a minimal, spec-adjacent fallback. Confirm exact
        // expected behavior with your switch/sponsor for unparseable input.
        $fields = [39 => '30'];
        return Iso8583Message::build('0110', $fields)->toWire();
    }

    private function baseResponseFields(Iso8583Message $request): array
    {
        $fields = [];
        // Echo back the fields a response is required to carry per spec —
        // PAN, processing code, amount, STAN, transmission time,
        // acquiring institution, terminal/merchant IDs.
        foreach ([2, 3, 4, 7, 11, 12, 13, 32, 37, 41, 42, 49] as $n) {
            if (isset($request->fields[$n])) {
                $fields[$n] = $request->fields[$n];
            }
        }
        return $fields;
    }
}
