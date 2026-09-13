<?php
declare(strict_types=1);

namespace Domain\Services\Compliance;

use PDO;

/**
 * SanctionsScreeningService
 * ==========================
 * Screens swap participants against sanctions/watch lists before a swap
 * is allowed to proceed, and records a compliance audit trail regardless
 * of outcome (screened-and-cleared swaps need a record too, not just hits).
 *
 * THIS IS A STARTING POINT, NOT A FINISHED COMPLIANCE PROGRAM:
 * - screenAgainstList() below is a LOCAL list lookup against a table you
 *   populate yourself. For real regulatory coverage you almost certainly
 *   need a licensed screening provider (Refinitiv World-Check, Dow Jones
 *   Risk & Compliance, ComplyAdvantage, etc.) or your central bank's own
 *   list distribution mechanism, not just UN/OFAC/AU consolidated lists
 *   downloaded and matched by hand. Wire a real provider's API into
 *   screenAgainstList() before relying on this in production.
 * - Name matching here is intentionally simple (normalized exact + substring)
 *   to be transparent about what it does and doesn't catch. Sanctions
 *   screening providers use fuzzy/phonetic matching (Soundex, Levenshtein,
 *   transliteration handling) specifically because sanctioned parties
 *   often appear with spelling variants -- this local fallback will miss
 *   those. Treat LOCAL_LIST mode as a stopgap, not the end state.
 * - Structured party data (name/ID/address) needs to actually be captured
 *   upstream first -- see PARTY_DATA_WIRING.md for where to add it to
 *   swap payloads, since most swap types currently only carry institution
 *   codes and account/wallet identifiers, not names.
 *
 * DESIGN:
 * - Fail CLOSED on a screening-provider outage by default (screening
 *   unavailable = swap blocked), configurable per deployment via
 *   $failOpenOnProviderError -- discuss this decision with compliance/legal
 *   before flipping it, since fail-open trades regulatory risk for
 *   availability.
 * - Every screening attempt is logged to compliance_screening_log,
 *   hit or clear, because "we screened and it was clean" is itself
 *   something an auditor will ask to see evidence of.
 */
class SanctionsScreeningService
{
    private PDO $db;
    private bool $failOpenOnProviderError;
    private string $mode; // 'LOCAL_LIST' | 'PROVIDER_API'
    private ?string $providerApiUrl;
    private ?string $providerApiKey;

    private int $providerTimeoutMs;

    public function __construct(
        PDO $db,
        string $mode = 'LOCAL_LIST',
        bool $failOpenOnProviderError = false,
        ?string $providerApiUrl = null,
        ?string $providerApiKey = null
    ) {
        $this->db = $db;
        $this->mode = $mode;
        $this->failOpenOnProviderError = $failOpenOnProviderError;
        $this->providerApiUrl = $providerApiUrl ?? getenv('SANCTIONS_PROVIDER_API_URL') ?: null;
        $this->providerApiKey = $providerApiKey ?? getenv('SANCTIONS_PROVIDER_API_KEY') ?: null;
        $this->providerTimeoutMs = (int)(getenv('SANCTIONS_PROVIDER_TIMEOUT_MS') ?: 10000);

        // Fail loudly here, at construction, rather than only per-call
        // inside screenParty()'s try/catch. Previously, selecting
        // PROVIDER_API without configuring a URL/key just meant every
        // single swap silently failed closed with a PROVIDER_ERROR,
        // indistinguishable from a real vendor outage — an ops person
        // would have to notice a pattern of blocked swaps to even
        // discover the misconfiguration. This surfaces as a visible
        // startup failure instead.
        if ($this->mode === 'PROVIDER_API' && (!$this->providerApiUrl || !$this->providerApiKey)) {
            throw new \RuntimeException(
                'SANCTIONS_SCREENING_MODE=PROVIDER_API requires both SANCTIONS_PROVIDER_API_URL ' .
                'and SANCTIONS_PROVIDER_API_KEY to be set. Refusing to start with an unusable ' .
                'screening provider configured — set both, or use LOCAL_LIST mode.'
            );
        }

        $this->ensureTableExists();
    }

    private function ensureTableExists(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS compliance_screening_log (
                    id BIGSERIAL PRIMARY KEY,
                    swap_reference VARCHAR(128) NOT NULL,
                    party_role VARCHAR(16) NOT NULL, -- 'originator' | 'beneficiary'
                    party_name TEXT,
                    party_identifier TEXT,
                    screening_mode VARCHAR(16) NOT NULL,
                    result VARCHAR(16) NOT NULL, -- 'CLEAR' | 'HIT' | 'PROVIDER_ERROR'
                    matched_entries JSONB,
                    screened_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
            ");
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS sanctions_watchlist (
                    id BIGSERIAL PRIMARY KEY,
                    full_name TEXT NOT NULL,
                    normalized_name TEXT NOT NULL,
                    aliases TEXT[],
                    id_numbers TEXT[],
                    list_source VARCHAR(64) NOT NULL, -- 'UN', 'OFAC', 'AU', 'LOCAL', etc.
                    added_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
                )
            ");
            $this->db->exec("
                CREATE INDEX IF NOT EXISTS idx_watchlist_normalized_name
                    ON sanctions_watchlist (normalized_name)
            ");
        } catch (\Throwable $e) {
            error_log('[SanctionsScreeningService] Failed to ensure tables exist: ' . $e->getMessage());
        }
    }

    /**
     * Screen a single party. Returns a decision structure; caller decides
     * what to do with a HIT (block, or route to manual compliance review --
     * see screenSwapParties() below for the recommended default: block).
     */
    public function screenParty(
        string $swapReference,
        string $partyRole,
        ?string $name,
        ?string $identifier = null
    ): array {
        if (empty($name)) {
            // No name captured at all -- this is itself a finding, not a
            // pass. Log it distinctly so "we never had a name to screen"
            // is visible separately from "we screened a name and it was
            // clean" in any audit.
            $this->logScreening($swapReference, $partyRole, null, $identifier, 'NO_NAME_CAPTURED', []);
            return [
                'result' => 'NO_NAME_CAPTURED',
                'blocked' => false, // decide with compliance/legal whether missing-name should itself block below threshold amounts
                'matches' => [],
            ];
        }

        try {
            $matches = $this->mode === 'PROVIDER_API'
                ? $this->screenAgainstProvider($name, $identifier)
                : $this->screenAgainstLocalList($name, $identifier);

            $result = empty($matches) ? 'CLEAR' : 'HIT';
            $this->logScreening($swapReference, $partyRole, $name, $identifier, $result, $matches);

            return [
                'result' => $result,
                'blocked' => $result === 'HIT',
                'matches' => $matches,
            ];

        } catch (\Throwable $e) {
            error_log("[SanctionsScreeningService] Provider error screening '{$name}': " . $e->getMessage());
            $this->logScreening($swapReference, $partyRole, $name, $identifier, 'PROVIDER_ERROR', ['error' => $e->getMessage()]);

            return [
                'result' => 'PROVIDER_ERROR',
                'blocked' => !$this->failOpenOnProviderError,
                'matches' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Screen both sides of a swap in one call. This is the method to wire
     * into SwapService -- see PARTY_DATA_WIRING.md for exactly where.
     *
     * Returns ['blocked' => bool, 'originator' => [...], 'beneficiary' => [...]]
     * Caller should throw/refuse the swap if 'blocked' is true.
     */
    public function screenSwapParties(
        string $swapReference,
        ?string $originatorName,
        ?string $originatorId,
        ?string $beneficiaryName,
        ?string $beneficiaryId
    ): array {
        $originator = $this->screenParty($swapReference, 'originator', $originatorName, $originatorId);
        $beneficiary = $this->screenParty($swapReference, 'beneficiary', $beneficiaryName, $beneficiaryId);

        return [
            'blocked' => $originator['blocked'] || $beneficiary['blocked'],
            'originator' => $originator,
            'beneficiary' => $beneficiary,
        ];
    }

    /**
     * Simple normalized exact/substring matching against a locally
     * maintained table. See class docblock -- this is a stopgap, not a
     * production-grade screening engine.
     */
    private function screenAgainstLocalList(string $name, ?string $identifier): array
    {
        $normalized = $this->normalizeName($name);

        $stmt = $this->db->prepare("
            SELECT full_name, list_source, id_numbers
            FROM sanctions_watchlist
            WHERE normalized_name = :exact
               OR :exact LIKE '%' || normalized_name || '%'
               OR normalized_name LIKE '%' || :exact || '%'
            LIMIT 20
        ");
        $stmt->execute([':exact' => $normalized]);
        $nameMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $idMatches = [];
        if ($identifier) {
            $idStmt = $this->db->prepare("
                SELECT full_name, list_source, id_numbers
                FROM sanctions_watchlist
                WHERE :identifier = ANY(id_numbers)
                LIMIT 20
            ");
            $idStmt->execute([':identifier' => $identifier]);
            $idMatches = $idStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return array_merge($nameMatches, $idMatches);
    }

    /**
     * Wire a real screening provider here. Left unimplemented (throws)
     * until you have a provider contract -- do not silently fall back to
     * the local list if PROVIDER_API mode was explicitly requested; that
     * would hide a real integration gap behind a weaker check.
     *
     * Contract a real vendor integration must satisfy, so wiring one up
     * is a configuration change, not a code change:
     *   - SANCTIONS_PROVIDER_API_URL / SANCTIONS_PROVIDER_API_KEY: request
     *     target and bearer token.
     *   - SANCTIONS_PROVIDER_TIMEOUT_MS (optional, default 10000).
     *   - Request: POST {url} with JSON body {"name": string, "identifier":
     *     string|null}.
     *   - Response: 2xx with JSON body {"matches": [...]} — an array,
     *     empty meaning clear. Any other shape (including a 2xx with an
     *     unparseable body or a missing/non-array "matches" key) is
     *     treated as a provider error, not a clear result — see below.
     *
     * HTTP conventions here match GenericBankClient::send() (SSL
     * verification on, configurable timeout).
     */
    private function screenAgainstProvider(string $name, ?string $identifier): array
    {
        if (!$this->providerApiUrl || !$this->providerApiKey) {
            throw new \RuntimeException('PROVIDER_API mode selected but SANCTIONS_PROVIDER_API_URL/SANCTIONS_PROVIDER_API_KEY not configured');
        }

        $ch = curl_init($this->providerApiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['name' => $name, 'identifier' => $identifier]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->providerApiKey,
            ],
            CURLOPT_TIMEOUT_MS => $this->providerTimeoutMs,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException("Screening provider request failed: HTTP {$httpCode} {$error}");
        }

        // A garbled or unexpected response shape must NOT be read as "no
        // matches" — that's a false negative for a security control.
        // json_decode() on malformed JSON returns null, and null['matches']
        // ?? [] previously silently produced an empty (= clear) result.
        // Anything that doesn't look like a real {"matches": [...]}
        // response is treated as a provider error, which correctly routes
        // into screenParty()'s fail-closed-by-default handling instead.
        $data = json_decode($response, true);
        if (!is_array($data) || !array_key_exists('matches', $data) || !is_array($data['matches'])) {
            throw new \RuntimeException(
                'Screening provider returned an unrecognized response shape ' .
                '(expected {"matches": [...]}); treating as a provider error, not a clear result.'
            );
        }

        return $data['matches'];
    }

    private function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9\s]/', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        return $name;
    }

    private function logScreening(
        string $swapReference,
        string $partyRole,
        ?string $name,
        ?string $identifier,
        string $result,
        array $matches
    ): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO compliance_screening_log
                    (swap_reference, party_role, party_name, party_identifier, screening_mode, result, matched_entries)
                VALUES
                    (:ref, :role, :name, :id, :mode, :result, :matches::jsonb)
            ");
            $stmt->execute([
                ':ref' => $swapReference,
                ':role' => $partyRole,
                ':name' => $name,
                ':id' => $identifier,
                ':mode' => $this->mode,
                ':result' => $result,
                ':matches' => json_encode($matches),
            ]);
        } catch (\Throwable $e) {
            error_log('[SanctionsScreeningService] Failed to log screening result: ' . $e->getMessage());
            // Deliberately does not throw -- a logging failure must not
            // block or unblock a swap; the screening DECISION already
            // happened above. But this failure itself needs visibility --
            // it's a compliance audit trail gap even if the swap decision
            // was correct.
        }
    }
}
