<?php
declare(strict_types=1);

namespace Infrastructure\Auth\Schemes;

use Infrastructure\Auth\AuthSchemeInterface;

/**
 * MutualTlsScheme — application-layer mutual certificate authentication.
 *
 * IMPORTANT — WHY THIS IS NOT NETWORK-LEVEL TLS CLIENT-CERT AUTH:
 * Real mTLS verifies the client's certificate during the TLS handshake
 * itself, before any HTTP request is even processed — that requires the
 * server terminating TLS to see and check the certificate. Railway's
 * public networking docs are explicit: "We currently do not support
 * external SSL certificates since we provision one for you" — Railway's
 * edge terminates TLS and there is no documented mechanism for a
 * Railway-hosted service to receive or verify an inbound client
 * certificate at the handshake level. Confirmed against Railway's
 * current docs as of this implementation; re-check if that changes.
 *
 * This scheme is the closest REAL equivalent achievable under that
 * constraint: the client signs the request body with its private key
 * and includes its certificate in the payload (same shape as
 * CertificateManager / verify_requester_signature() already use for
 * ZURUBANK elsewhere in this codebase); the server verifies the
 * signature against the presented certificate using openssl_verify().
 * This is genuine public-key cryptographic mutual authentication — it
 * is NOT theater — it just happens at the application layer (inside the
 * request body) rather than the TLS transport layer. Be precise about
 * this distinction when documenting/auditing this scheme: do not call
 * it "mTLS" in compliance material without this caveat, since an
 * auditor familiar with the term will expect handshake-level enforcement.
 *
 * Context shape expected:
 *   verify(): ['expected_ca_cert' => PEM string of the CA/counterparty's
 *              trusted public cert (or the counterparty's own public
 *              cert directly for a two-party pinned relationship)]
 *   sign():   ['private_key' => PEM string, 'certificate' => PEM string,
 *              'requester' => string label to embed in the signed payload]
 */
final class MutualTlsScheme implements AuthSchemeInterface
{
    public function name(): string
    {
        return 'MUTUAL_TLS';
    }

    /**
     * $rawBody here is expected to be the JSON request body containing
     * 'certificate' and 'signature' fields, same shape ZURUBANK's own
     * crypto.php already produces/consumes. $headers is accepted for
     * interface compatibility but unused — the credential lives in the
     * body, not headers, for this scheme.
     */
    public function verify(array $headers, string $rawBody, array $context): bool
    {
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            return false;
        }

        $certificate = $decoded['certificate'] ?? null;
        $signature = $decoded['signature'] ?? null;

        if (!$certificate || !$signature) {
            return false;
        }

        $trustedCert = $context['expected_ca_cert'] ?? null;
        if (!$trustedCert) {
            error_log("[MutualTlsScheme] No expected_ca_cert configured in context — cannot verify");
            return false;
        }

        // Verify the presented certificate was actually issued/trusted —
        // for a pinned two-party relationship (VouchMorph <-> ZURUBANK),
        // this is a direct string/fingerprint match against the known
        // counterparty certificate rather than a full CA chain walk.
        // If a real CA hierarchy is introduced later, this is the point
        // to replace with openssl_x509_checkpurpose / chain verification.
        if (!$this->certificatesMatch($certificate, $trustedCert)) {
            error_log("[MutualTlsScheme] Presented certificate does not match expected/trusted certificate");
            return false;
        }

        // Verify the signature over the payload (excluding signature/
        // certificate fields themselves) using the public key extracted
        // from the presented certificate.
        $payloadToVerify = $decoded;
        unset($payloadToVerify['signature'], $payloadToVerify['certificate']);
        ksort($payloadToVerify);
        $jsonToVerify = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $publicKeyResource = openssl_pkey_get_public($certificate);
        if (!$publicKeyResource) {
            error_log("[MutualTlsScheme] Could not extract public key from presented certificate");
            return false;
        }

        $decodedSignature = base64_decode($signature, true);
        if ($decodedSignature === false) {
            return false;
        }

        $result = openssl_verify($jsonToVerify, $decodedSignature, $publicKeyResource, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }

    /**
     * Signs an outbound request. Returns headers (empty here, per the
     * interface contract) — the actual signature/certificate are meant
     * to be merged into the JSON body by the caller, same as
     * CertificateManager::createSignedRequest() already does elsewhere.
     * This keeps the AuthSchemeInterface contract honest: it declares
     * sign() returns headers, and for a body-embedded scheme like this
     * one, there genuinely are none to add — callers needing the signed
     * body itself should use signPayload() below directly instead of
     * going through AuthSchemeRegistry::sign(), which only surfaces
     * headers.
     */
    public function sign(string $rawBody, array $context): array
    {
        return [];
    }

    /**
     * Not part of AuthSchemeInterface — this scheme's real signing
     * output is a modified payload (with signature+certificate fields
     * added), not headers, so it needs its own entry point. Call this
     * directly rather than through AuthSchemeRegistry::sign() when
     * using MUTUAL_TLS as an outbound scheme.
     */
    public function signPayload(array $payload, array $context): array
    {
        $privateKeyPem = $context['private_key'] ?? null;
        $certificatePem = $context['certificate'] ?? null;
        $requester = $context['requester'] ?? 'VOUCHMORPH';

        if (!$privateKeyPem || !$certificatePem) {
            throw new \RuntimeException('MutualTlsScheme::signPayload requires private_key and certificate in context');
        }

        $payload['requester'] = $requester;
        $payload['timestamp'] = time();

        $payloadToSign = $payload;
        ksort($payloadToSign);
        $jsonToSign = json_encode($payloadToSign, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $privateKeyResource = openssl_pkey_get_private($privateKeyPem);
        if (!$privateKeyResource) {
            throw new \RuntimeException('MutualTlsScheme::signPayload could not load private key: ' . openssl_error_string());
        }

        $signature = '';
        $signed = openssl_sign($jsonToSign, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256);
        if (!$signed) {
            throw new \RuntimeException('MutualTlsScheme::signPayload failed to sign: ' . openssl_error_string());
        }

        $payload['signature'] = base64_encode($signature);
        $payload['certificate'] = $certificatePem;

        return $payload;
    }

    /**
     * Pinned direct-match comparison. Normalizes whitespace/line-ending
     * differences that commonly creep in when PEM strings pass through
     * env vars (see the '\n' vs actual-newline handling already present
     * in this codebase's helpers/crypto.php for the same reason).
     */
    private function certificatesMatch(string $presented, string $trusted): bool
    {
        $normalize = static function (string $pem): string {
            $pem = str_replace(['\\n', '\\r', "\r"], ["\n", '', ''], $pem);
            return trim(preg_replace('/\s+/', '', $pem));
        };

        return hash_equals($normalize($trusted), $normalize($presented));
    }
}
