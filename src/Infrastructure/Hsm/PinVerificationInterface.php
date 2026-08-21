<?php
declare(strict_types=1);

namespace Infrastructure\Hsm;

/**
 * PIN block verification MUST happen inside a certified Hardware
 * Security Module (Thales payShield, Utimaco, Futurex, etc.) — never
 * in application code, and never with key material stored in PHP,
 * environment variables, or application config. This is a hard
 * PCI-DSS / PCI-PIN requirement, not a style preference.
 *
 * A real ATM/POS terminal sends field 52 (PIN block) encrypted under a
 * key the TERMINAL's own secure hardware shares with the ACQUIRER's
 * HSM (via key ceremony / key injection, itself a physical-security
 * process). The acquirer's HSM translates it under a zone key shared
 * with the SWITCH's HSM, which translates it again under a key shared
 * with the ISSUER's (VouchMorph's) HSM — a chain of HSM-to-HSM
 * translations, cleartext PIN never existing outside HSM hardware at
 * any point. This interface represents ONLY the final "ask the issuer
 * HSM: is this PIN correct for this card" step.
 *
 * Implement this interface against your actual HSM vendor's API once
 * one is provisioned. Nothing else in this codebase should attempt PIN
 * verification any other way.
 */
interface PinVerificationInterface
{
    /**
     * @param string $encryptedPinBlock The raw field-52 bytes/hex from the
     *   inbound ISO 8583 message, still encrypted — this interface's
     *   implementation hands it to the HSM as-is.
     * @param string $pan The card's PAN (needed for PIN block format
     *   derivation per ISO 9564 — again, only the HSM ever sees this
     *   combined with the decrypted PIN).
     * @param string $keyZoneId Identifies which zone key/ HSM key
     *   slot to use for this request — resolved from which
     *   switch/acquirer the message arrived via.
     * @return bool true only if the HSM confirms the PIN is correct.
     */
    public function verifyPin(string $encryptedPinBlock, string $pan, string $keyZoneId): bool;
}

/**
 * DEVELOPMENT / TESTING ONLY. Always returns false, and logs loudly if
 * ever invoked, so it is impossible to mistake this for a working
 * integration or to accidentally approve a real transaction against a
 * fake PIN check. Wire in a real vendor HSM implementation before any
 * ISO 8583 PIN-carrying message is accepted from a real terminal.
 */
class UnimplementedHsmPinVerification implements PinVerificationInterface
{
    public function verifyPin(string $encryptedPinBlock, string $pan, string $keyZoneId): bool
    {
        error_log("[HSM] CRITICAL: PIN verification was invoked but no real HSM is configured. " .
            "Declining by default — this must be wired to a real HSM vendor API before " .
            "accepting real terminal traffic. keyZoneId={$keyZoneId}");
        return false;
    }
}
