<?php
declare(strict_types=1);

namespace Infrastructure\Iso8583;

use Domain\Services\CardService;
use PDO;

/**
 * Bridges real ISO 8583 authorization messages (0100/0200, from a
 * terminal via an acquirer or the national switch) into the SAME
 * authorization logic authorize.php already uses over REST — no
 * duplicated business rules, this is purely a protocol adapter.
 *
 * WHAT THIS DOES NOT SOLVE (flagged explicitly, not silently assumed):
 *
 * 1. dynamic_code / PIN. Field 52 (PIN block) is the standard ISO 8583
 *    carrier for a cardholder-entered PIN, DES/3DES-encrypted under a
 *    terminal/zone key. CardService's TOTP-based dynamic_code has NO
 *    real-world terminal analog — no physical ATM/POS keypad produces
 *    a TOTP code, they produce an encrypted PIN block. Two real options,
 *    NEITHER implemented here, both requiring a product decision:
 *      a) Accept a standard PIN block in field 52, decrypt it under a
 *         real HSM/key-zone (requires actual HSM infrastructure — a
 *         hard requirement for PCI-DSS PIN security anyway), and store
 *         a PIN verification value instead of / alongside the TOTP
 *         secret.
 *      b) Keep TOTP but only for a companion-app-driven "tap to
 *         generate code, then enter it at a keypad-having terminal"
 *         flow — unusual, and most real terminals have no way to
 *         prompt for a 6-digit app code instead of a PIN.
 *    This bridge currently maps field 52 through as $dynamicCode
 *    verbatim for structural completeness, but it will NOT pass
 *    verifyDynamicCode()'s real TOTP check as-is. This is the single
 *    biggest remaining design gap for real terminal acceptance.
 *
 * 2. Track 2 (field 35) / EMV chip data are accepted on the wire but
 *    not yet used to resolve the card — resolution is by PAN (field 2)
 *    only, matching authorize.php's existing card_suffix/PAN-based
 *    lookup. Real terminal certification will require verifying track
 *    data / EMV cryptograms, which needs its own workstream.
 *
 * 3. Reversals (MTI 0400/0420) are not implemented — a real deployment
 *    needs these for timeout/network-failure recovery per network
 *    rules. Flagging as a gap, not pretending it's covered.
 */
class Iso8583AuthorizationBridge
{
    private PDO $db;
    private CardService $cardService;

    public function __construct(PDO $db, CardService $cardService)
    {
        $this->db = $db;
        $this->cardService = $cardService;
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
            default => $this->buildUnsupportedMtiResponse($request)->toWire(),
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
     * routes into the exact same CardService::authorizePooledSwipe()
     * used by authorize.php, so approval/decline logic and TOTP
     * verification are identical regardless of which protocol the
     * request arrived over.
     */
    private function handleFinancialRequest(Iso8583Message $request, string $responseMti): Iso8583Message
    {
        $pan = $request->fields[2] ?? null;
        $amountMinorUnits = $request->fields[4] ?? '000000000000';
        $dynamicCode = $request->fields[52] ?? null; // see class header note #1 — will not pass real TOTP check as-is
        $merchantId = $request->fields[42] ?? null;
        $terminalId = $request->fields[41] ?? null;
        $stan = $request->fields[11] ?? null;

        if (!$pan) {
            return $this->buildDeclineResponse($request, $responseMti, '30');
        }

        $amount = ((float)$amountMinorUnits) / 100;
        $cardSuffix = $this->resolveCardSuffixFromPan($pan);

        if (!$cardSuffix) {
            return $this->buildDeclineResponse($request, $responseMti, '14');
        }

        $merchantContext = [
            'merchant_reference' => $stan,
            'merchant_id' => $merchantId,
            'terminal_id' => $terminalId,
            'channel' => 'ISO8583',
            'dynamic_code' => $dynamicCode, // see class header note #1
        ];

        $result = $this->cardService->authorizePooledSwipe($cardSuffix, $amount, $merchantContext);

        if (!($result['authorized'] ?? false)) {
            $responseCode = match ($result['response_code'] ?? '96') {
                '57' => '55', // dynamic code missing/invalid -> nearest ISO 8583 equivalent (incorrect PIN)
                '51' => '51', // amount exceeds held total -> insufficient funds (same code, ISO 8583 already uses '51')
                default => '05', // generic decline
            };
            return $this->buildDeclineResponse($request, $responseMti, $responseCode);
        }

        // Approved — queue real debit/settlement exactly like authorize.php
        // does, via the SAME card_pool_finalize_queue table, so the async
        // worker doesn't need to know or care which protocol the approval
        // came in over.
        try {
            $queueStmt = $this->db->prepare("
                INSERT INTO card_pool_finalize_queue (hook_reference, merchant_context, status)
                VALUES (?, ?::jsonb, 'PENDING')
            ");
            $queueStmt->execute([$result['hook_reference'], json_encode($merchantContext)]);
        } catch (\Throwable $e) {
            error_log("[Iso8583AuthorizationBridge] Failed to queue finalize for hook {$result['hook_reference']}: " . $e->getMessage());
            // Approval already decided — do not flip to a decline over a
            // queueing failure, same posture authorize.php already takes
            // for its own queue insert. Log loudly; ops needs to catch this.
        }

        return $this->buildApprovedResponse($request, $responseMti, $result['auth_code'] ?? $this->generateAuthCode());
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
