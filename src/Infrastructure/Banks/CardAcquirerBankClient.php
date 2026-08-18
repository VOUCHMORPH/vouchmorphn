<?php
// Target path in repo: src/Infrastructure/Banks/CardAcquirerBankClient.php
// REPLACES the v1 version — adds card-LOAD (destination) support as a
// separate, independently-gated capability from card-SOURCE (acquiring).

declare(strict_types=1);

namespace Infrastructure\Banks;

/**
 * CardAcquirerBankClient
 *
 * Handles TWO independent capabilities against a card-network participant
 * (e.g. FNBB), because they are genuinely different commercial products and
 * must not be assumed to both exist just because one does:
 *
 *   SOURCE (acquiring): pulling funds FROM a Visa/Mastercard card.
 *     verifyAssetSigned() / placeHold() / debitFunds() / releaseHold()
 *     = pre-auth(optional) / authorize / capture / void
 *     Gated by: card_acquirer.source_enabled (default true if this class
 *     is wired at all, since acquiring is the lighter, more commonly
 *     available product)
 *
 *   DESTINATION (load/push): pushing funds ONTO a Visa/Mastercard card.
 *     processDepositWithProof()
 *     = a card-load / push-to-card call (Visa Direct / Mastercard Send
 *     style, or FNBB's own load product if they have one)
 *     Gated by: card_acquirer.destination_enabled (default FALSE — this
 *     is a materially harder product to get from an acquirer and must
 *     never be silently assumed available just because source-side
 *     acquiring is configured)
 *
 * If destination_enabled is false (the default) and something tries to
 * deposit onto a card through this client, it fails LOUDLY with a message
 * telling the caller exactly what commercial capability is missing —
 * never silently downgrades to some other behavior.
 */
class CardAcquirerBankClient extends GenericBankClient
{
    private const CVV_FIELDS = ['cvv', 'card_cvv', 'security_code'];

    // ========================================================================
    // SOURCE SIDE — unchanged from v1 (verifyAssetSigned / placeHold /
    // debitFunds / releaseHold / getBalance). Included here in full so this
    // file is a complete drop-in replacement, not a partial patch.
    // ========================================================================

   public function verifyAssetSigned(array $payload): array
    {
        if (!$this->isSourceEnabled()) {
            return $this->disabledCapabilityResponse('source (acquiring)', 'verified');
        }

        $preAuthEnabled = (bool)($this->config['card_acquirer']['pre_auth_check'] ?? false);

        if (!$preAuthEnabled) {
            $hasToken = !empty($payload['card_token']) || !empty($payload['source_identifier']);
            return [
                'success' => $hasToken,
                'verified' => $hasToken,
                'data' => [
                    'message' => $hasToken
                        ? 'Card token present — deferring real check to authorization'
                        : 'No card token supplied',
                    'verified' => $hasToken,
                ],
                'status_code' => $hasToken ? 200 : 400,
            ];
        }

        $preAuthAmount = (float)($this->config['card_acquirer']['pre_auth_amount'] ?? 1.00);

        // ============================================================
        // FIX: stripCvvAfterUse() used to run BEFORE signing here, and
        // CVV was never restored — same bug already fixed in
        // placeHold() (see the FIX comment there). The acquirer's mock
        // apparently requires CVV in the signed+sent payload for
        // PRE_AUTH_CHECK, same as it does for AUTHORIZE. Confirmed
        // live: every /Preauth.php call returned "Asset verification
        // failed: Authentication failed" regardless of PAN — both the
        // approved and the declined test PAN produced the byte-
        // identical error, meaning neither ever reached PAN-specific
        // evaluation; the request was being rejected before that point.
        //
        // Fix is the same as placeHold(): sign the payload AS SENT,
        // cvv included, so what's hashed and what's transmitted match.
        // If CVV must stay out of application logs for PCI reasons,
        // redact it only at the log call site (see
        // createSignedPayload()'s own error_log() calls) — never strip
        // it from what's actually signed and sent.
        // ============================================================
        $preAuthPayload = $payload;
        $preAuthPayload['amount'] = $preAuthAmount;
        $preAuthPayload['action'] = 'PRE_AUTH_CHECK';

        $result = $this->send('verify_asset', $this->createSignedPayload($preAuthPayload, 'VOUCHMORPH'));
        $data = $this->unwrapNestedData($result['data'] ?? []);

        return [
            'success' => $result['success'] ?? false,
            'verified' => $result['success'] ?? false,
            'data' => $data,
            'message' => $data['message'] ?? ($result['success'] ? 'Pre-auth check passed' : 'Pre-auth check failed'),
            'status_code' => $result['status_code'] ?? 0,
            'curl_error' => $result['curl_error'] ?? null,
            'raw_response' => $result['raw_response'] ?? null,
        ];
    }
   public function placeHold(array $payload): array
    {
        if (!$this->isSourceEnabled()) {
            return $this->disabledCapabilityResponse('source (acquiring)', 'hold_placed');
        }
        $maxSingleAuth = (float)($this->config['card_acquirer']['max_single_auth_amount'] ?? 0);
        $requestedAmount = (float)($payload['amount'] ?? 0);
        if ($maxSingleAuth > 0 && $requestedAmount > $maxSingleAuth) {
            return [
                'success' => false,
                'hold_placed' => false,
                'message' => "Amount exceeds this card acquirer's configured single-transaction limit ({$maxSingleAuth}).",
                'status_code' => 0,
            ];
        }
        if (!isset($payload['reference'])) {
            $payload['reference'] = 'AUTH_' . uniqid();
        }
        if (!isset($payload['expiry'])) {
            $payload['expiry'] = date('Y-m-d H:i:s', strtotime('+' . $this->authorizationWindowSeconds() . ' seconds'));
        }
        $payload['action'] = 'AUTHORIZE';

        // ============================================================
        // FIX: CVV was being stripped BEFORE signing, then spliced back
        // into the payload AFTER signing (via the loop that used to sit
        // here). That meant the bytes actually transmitted to FNBB never
        // matched the bytes that were hashed - the acquirer's response
        // verification recomputes the hash from every field it received,
        // sees a payload with a cvv field the signature never covered,
        // and rejects it as a signature mismatch. Confirmed live: every
        // /Authorize.php call returned HTTP 401 "Authentication failed"
        // regardless of the actual PAN/decline status, because the
        // request never got past signature verification.
        //
        // The fix is to sign the payload AS SENT, cvv included, so what
        // was hashed and what was transmitted are identical. If CVV must
        // stay out of application logs for PCI reasons, redact it only
        // at the log call site (see createSignedPayload()'s own
        // error_log() calls, or scrub $payload before logging here) -
        // never strip it from what's actually signed and sent.
        // ============================================================
        $signedPayload = $this->createSignedPayload($payload, 'VOUCHMORPH');

        $result = $this->send('place_hold', $signedPayload, $payload['access_token'] ?? null);

        // ============================================================
        // FIX: FNBB (via ZuruBank's mock) nests the real authorization
        // fields one level deeper than this class expected -
        // {"success":true,"message":"Authorized","data":{
        //   "authorization_reference":"...","authorization_code":"...",
        //   "status":"ACTIVE",...}}
        // -- rather than returning them flattened at the top of "data".
        // Without unwrapping, $authRef below always resolved to null even
        // on a genuine approval (confirmed live: HTTP 200, "status":
        // "ACTIVE", a real authorization_code present, no decline_code
        // anywhere - and this method still reported hold_placed=false,
        // using the bank's own "Authorized" message as the "decline
        // reason" in the log). unwrapNestedData() merges the inner object
        // up so every field below - and the raw $data returned to the
        // caller - is reachable at a single, consistent level regardless
        // of which shape the specific endpoint used.
        // ============================================================
        $data = $this->unwrapNestedData($result['data'] ?? []);
        $authRef = $data['authorization_reference'] ?? $data['auth_reference'] ?? $data['hold_reference'] ?? null;
        $authCode = $data['authorization_code'] ?? $data['auth_code'] ?? null;
        if (!$result['success'] || empty($authRef)) {
            error_log("[CardAcquirerBankClient] Authorization declined or returned no reference: "
                . ($data['message'] ?? 'no message') . " / decline_code=" . ($data['decline_code'] ?? 'n/a'));
            return [
                'success' => false,
                'hold_placed' => false,
                'message' => $data['message'] ?? ($result['curl_error'] ?? 'Card authorization declined'),
                'status_code' => $result['status_code'] ?? 0,
                'data' => $data,
                'raw_response' => $result['raw_response'] ?? null,
            ];
        }
        $responseForVerification = $data;
        unset($responseForVerification['signature'], $responseForVerification['certificate']);
        return [
            'success' => true,
            'hold_placed' => true,
            'hold_reference' => $authRef,
            'hold_id' => $authCode,
            'status' => 'ACTIVE',
            'data' => $data,
            'message' => $data['message'] ?? 'Card authorized',
            'status_code' => $result['status_code'] ?? 0,
            'raw_response' => $result['raw_response'] ?? null,
            'signature' => $data['signature'] ?? null,
            'certificate' => $data['certificate'] ?? null,
            'original_payload' => $responseForVerification,
            'timestamp' => $data['timestamp'] ?? time(),
        ];
    }
    
    public function debitFunds(array $payload): array
    {
        if (!$this->isSourceEnabled()) {
            return $this->disabledCapabilityResponse('source (acquiring)', 'debited');
        }

        $authRef = $payload['hold_reference'] ?? $payload['reference'] ?? null;
        if (empty($authRef)) {
            return [
                'success' => false,
                'debited' => false,
                'message' => 'authorization_reference (hold_reference) is required to capture — no raw card charge path exists.',
                'data' => [],
            ];
        }

        $capturePayload = [
            'reference' => $payload['reference'] ?? ('CAPTURE_' . uniqid()),
            'authorization_reference' => $authRef,
            'amount' => $payload['amount'] ?? 0,
            'action' => 'CAPTURE',
        ];

        $signedPayload = $this->createSignedPayload($capturePayload, 'VOUCHMORPH');
        $result = $this->send('debit_funds', $signedPayload, $payload['access_token'] ?? null);

        // FIX: same nested-data shape as placeHold() (see comment there) -
        // apply the same unwrap so transaction_reference isn't missed if
        // Capture.php follows the same convention as Preauth.php/Authorize.php.
        $data = $this->unwrapNestedData($result['data'] ?? []);
        $txRef = $data['transaction_reference'] ?? $data['capture_reference'] ?? null;

        if (!$result['success'] || empty($txRef)) {
            return [
                'success' => false,
                'debited' => false,
                'message' => $data['message'] ?? ($result['curl_error'] ?? 'Capture failed'),
                'data' => $data,
                'status_code' => $result['status_code'] ?? 0,
                'raw_response' => $result['raw_response'] ?? null,
            ];
        }

        return [
            'success' => true,
            'debited' => true,
            'transaction_reference' => $txRef,
            'status' => $data['status'] ?? 'COMPLETED',
            'data' => $data,
            'message' => $data['message'] ?? 'Capture successful',
            'status_code' => $result['status_code'] ?? 0,
            'raw_response' => $result['raw_response'] ?? null,
        ];
    }

    public function releaseHold(array $payload): array
    {
        if (!$this->isSourceEnabled()) {
            return $this->disabledCapabilityResponse('source (acquiring)', 'released');
        }

        $authRef = $payload['hold_reference'] ?? null;
        if (empty($authRef)) {
            return ['success' => false, 'released' => false, 'message' => 'authorization_reference required to void', 'data' => []];
        }

        $voidPayload = $this->createSignedPayload([
            'reference' => $payload['reference'] ?? ('VOID_' . uniqid()),
            'authorization_reference' => $authRef,
            'action' => 'VOID',
            'reason' => $payload['reason'] ?? 'Released by VouchMorph',
        ], 'VOUCHMORPH');

        $result = $this->send('release_hold', $voidPayload);
        // FIX: same nested-data shape as placeHold() (see comment there) -
        // apply the same unwrap in case Void.php follows the same convention.
        $data = $this->unwrapNestedData($result['data'] ?? []);

        return [
            'success' => $result['success'] ?? false,
            'released' => $result['success'] ?? false,
            'status' => $data['status'] ?? ($result['success'] ? 'VOIDED' : 'FAILED'),
            'data' => $data,
            'message' => $data['message'] ?? ($result['success'] ? 'Authorization voided' : 'Void failed'),
            'status_code' => $result['status_code'] ?? 0,
            'raw_response' => $result['raw_response'] ?? null,
        ];
    }

    public function getBalance(array $payload): array
    {
        error_log("[CardAcquirerBankClient] getBalance: NO REAL BALANCE EXISTS FOR CARD SOURCES. "
            . "Returning a synthetic ceiling for pre-authorization sizing purposes only.");

        $maxSingleAuth = (float)($this->config['card_acquirer']['max_single_auth_amount'] ?? 0);
        $requested = (float)($payload['requested_amount'] ?? $payload['amount'] ?? 0);

        $syntheticCeiling = $maxSingleAuth > 0
            ? ($requested > 0 ? min($requested, $maxSingleAuth) : $maxSingleAuth)
            : $requested;

        return [
            'success' => true,
            'data' => [
                'balance' => $syntheticCeiling,
                'available_balance' => $syntheticCeiling,
                'currency' => $payload['currency'] ?? 'BWP',
                'is_synthetic' => true,
                'message' => 'Synthetic value — card sources have no queryable real balance.',
            ],
            'status_code' => 200,
        ];
    }

    // ========================================================================
    // DESTINATION SIDE — NEW IN v2. Pushing funds ONTO a card.
    //
    // This is called via GenericInstitutionAdapter::credit() ->
    // $this->bankClient->processDepositWithProof($creditPayload), the exact
    // same entry point every other institution's card-load-equivalent
    // (processDeposit) uses. Overriding it here is what makes CARD a real
    // destination asset type instead of just accepted-then-mishandled.
    //
    // GATED SEPARATELY FROM SOURCE ABOVE. Do not assume this works just
    // because acquiring (source) is configured — confirm with FNBB (or
    // whichever participant) specifically whether they offer a card-load /
    // push-to-card product before setting destination_enabled: true.
    // ========================================================================
    public function processDepositWithProof(array $payload): array
    {
        if (!$this->isDestinationEnabled()) {
            error_log("[CardAcquirerBankClient] processDepositWithProof: card LOAD is not enabled for "
                . ($this->config['provider_code'] ?? 'unknown')
                . " — this participant may support pulling FROM cards (acquiring) without supporting "
                . "pushing TO cards (load/Visa Direct/Mastercard Send). Confirm with the acquirer whether "
                . "they offer a card-load product before setting card_acquirer.destination_enabled: true "
                . "in participants.yaml.");
            return [
                'success' => false,
                'credited' => false,
                'message' => "This card acquirer is not configured for card LOAD (destination) — only card "
                    . "SOURCE (acquiring) is enabled. Pushing funds to a card requires a separate commercial "
                    . "capability (e.g. Visa Direct / Mastercard Send) that must be confirmed and enabled "
                    . "explicitly.",
                'data' => [],
                'status_code' => 0,
            ];
        }

        error_log("=== CARD ACQUIRER: card load (processDepositWithProof) ===");

        $cardToken = $payload['card_token'] ?? $payload['destination_identifier'] ?? null;
        if (empty($cardToken)) {
            return [
                'success' => false,
                'credited' => false,
                'message' => 'card_token (destination_identifier) required to load a card',
                'data' => [],
            ];
        }

        $maxSingleLoad = (float)($this->config['card_acquirer']['max_single_load_amount'] ?? 0);
        $requestedAmount = (float)($payload['amount'] ?? 0);
        if ($maxSingleLoad > 0 && $requestedAmount > $maxSingleLoad) {
            return [
                'success' => false,
                'credited' => false,
                'message' => "Amount exceeds this card acquirer's configured single card-load limit ({$maxSingleLoad}).",
                'data' => [],
            ];
        }

        $loadPayload = [
            'reference' => $payload['reference'] ?? ('LOAD_' . uniqid()),
            'destination_card_token' => $cardToken,
            'amount' => $requestedAmount,
            'currency' => $payload['currency'] ?? 'BWP',
            'action' => 'CARD_LOAD',
        ];

        $signedPayload = $this->createSignedPayload($loadPayload, 'VOUCHMORPH');
        $result = $this->send('process_deposit', $signedPayload, $payload['access_token'] ?? null);

        // FIX: same nested-data shape as placeHold() (see comment there) -
        // apply the same unwrap in case Cardload.php follows the same convention.
        $data = $this->unwrapNestedData($result['data'] ?? []);
        $txRef = $data['transaction_reference'] ?? $data['load_reference'] ?? null;

        if (!$result['success'] || empty($txRef)) {
            return [
                'success' => false,
                'credited' => false,
                'message' => $data['message'] ?? ($result['curl_error'] ?? 'Card load failed'),
                'data' => $data,
                'status_code' => $result['status_code'] ?? 0,
                'raw_response' => $result['raw_response'] ?? null,
            ];
        }

        return [
            'success' => true,
            'credited' => true,
            'transaction_reference' => $txRef,
            'status' => $data['status'] ?? 'COMPLETED',
            'new_balance' => $data['new_balance'] ?? null,  // may legitimately be null — see note
            'data' => $data,
            'message' => $data['message'] ?? 'Card loaded successfully',
            'status_code' => $result['status_code'] ?? 0,
            'raw_response' => $result['raw_response'] ?? null,
        ];
    }

    // ========================================================================
    // CAPABILITY GATES
    // ========================================================================

    private function isSourceEnabled(): bool
    {
        // Default true: if this client is wired up at all, source (acquiring)
        // is the assumed baseline product — the lighter, more commonly
        // available one. Explicit false still overrides.
        return (bool)($this->config['card_acquirer']['source_enabled'] ?? true);
    }

    private function isDestinationEnabled(): bool
    {
        // Default FALSE, deliberately — never assume push-to-card capability
        // exists just because acquiring does. Must be turned on explicitly
        // once confirmed with the acquirer.
        return (bool)($this->config['card_acquirer']['destination_enabled'] ?? false);
    }

    private function disabledCapabilityResponse(string $capabilityLabel, string $successKey): array
    {
        return [
            'success' => false,
            $successKey => false,
            'message' => "This card acquirer does not have {$capabilityLabel} enabled in participants.yaml.",
            'data' => [],
            'status_code' => 0,
        ];
    }

    // ========================================================================
    // HELPERS — unchanged from v1
    // ========================================================================

    private function authorizationWindowSeconds(): int
    {
        $configured = $this->config['card_acquirer']['authorization_window_seconds'] ?? null;
        if ($configured !== null) {
            return (int)$configured;
        }
        error_log("[CardAcquirerBankClient] WARNING: authorization_window_seconds not configured — "
            . "falling back to a 7-day industry-norm placeholder. Confirm the real value with the acquirer.");
        return 7 * 24 * 60 * 60;
    }

    private function stripCvvAfterUse(array $payload): array
    {
        foreach (self::CVV_FIELDS as $field) {
            unset($payload[$field]);
        }
        return $payload;
    }

    // ========================================================================
    // NEW: shared unwrap helper
    //
    // GenericBankClient::send() decodes the bank's entire response body
    // into 'data' as-is. For most participants that body is already flat
    // ({"success":true,"authorization_reference":"..."}), but FNBB (via
    // ZuruBank's mock, at least for Preauth.php/Authorize.php) nests the
    // real fields one level deeper: {"success":true,"message":"...",
    // "data":{"authorization_reference":"...", ...}}. Every method in this
    // class reads fields directly off the top of $result['data'], so
    // without unwrapping, those reads silently return null on an
    // otherwise-successful response. This merges the inner object up
    // (inner values win on key collision) so both shapes work identically.
    // Safe to call even when the response is already flat - if there's no
    // nested 'data' array, this is a no-op.
    // ========================================================================
    private function unwrapNestedData(array $data): array
    {
        if (isset($data['data']) && is_array($data['data'])) {
            $data = array_merge($data, $data['data']);
        }
        return $data;
    }
}
