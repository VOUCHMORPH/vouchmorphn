<?php
declare(strict_types=1);

namespace Domain\Services\Messaging;

use RuntimeException;

/**
 * Converts SwapService payloads into ISO 20022 XML and ISO 8583 field arrays.
 *
 * Deposit / debit / settlement  -> ISO 20022
 * Card and ATM legs             -> ISO 8583
 * Hold                          -> proprietary (see VM-MSG-001 section 7)
 *
 * $participants[inst] must carry 'bic' and, for card rails, 'acquirer_id'.
 */
final class IsoMessageMapper
{
    private const CCY_NUMERIC = ['BWP' => '072', 'ZAR' => '710', 'USD' => '840', 'AOA' => '973'];

    public function __construct(private array $participants) {}

    // =================================================================
    // ISO 20022
    // =================================================================

    /**
     * DEPOSIT -> pacs.008 FIToFICustomerCreditTransfer.
     * For MULTI_SOURCE call this once per leg; pass the same $executionRef
     * and increment $legIndex.
     */
    public function toPacs008(
        array $payload,
        int $legIndex = 1,
        int $legCount = 1,
        ?array $leg = null,
    ): string {
        $executionRef = $payload['reference'] ?? throw new RuntimeException('reference required');
        $legRef       = $this->legRef($executionRef, $legIndex);

        $sourceInst = $leg['institution'] ?? $payload['from_institution'] ?? $payload['source_institution'];
        $destInst   = $payload['to_institution'] ?? $payload['destination_institution'];
        $currency   = $leg['currency'] ?? $payload['currency'] ?? 'BWP';

        $gross = (float) ($leg['amount'] ?? $payload['amount'] ?? 0);
        $fee   = (float) ($leg['fee_event'] ?? $payload['fee'] ?? 0);
        $net   = round($gross - $fee, 2);

        $x = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pacs.008.001.10"/>'
        );

        $doc = $x->addChild('FIToFICstmrCdtTrf');

        $hdr = $doc->addChild('GrpHdr');
        $hdr->addChild('MsgId', $executionRef . '-G' . $legIndex);
        $hdr->addChild('CreDtTm', date('c'));
        $hdr->addChild('NbOfTxs', '1');
        $sttlm = $hdr->addChild('SttlmInf');
        $sttlm->addChild('SttlmMtd', 'CLRG');
        $sttlm->addChild('ClrSys')->addChild('Prtry', 'BISS');

        $tx = $doc->addChild('CdtTrfTxInf');

        $pmtId = $tx->addChild('PmtId');
        $pmtId->addChild('InstrId', $legRef);
        $pmtId->addChild('EndToEndId', $executionRef);   // same across all legs
        $pmtId->addChild('TxId', $legRef . '-TX');
        $pmtId->addChild('UETR', $this->uetr());

        $amt = $tx->addChild('IntrBkSttlmAmt', number_format($net, 2, '.', ''));
        $amt->addAttribute('Ccy', $currency);

        $tx->addChild('ChrgBr', 'SLEV');
        if ($fee > 0) {
            $ci = $tx->addChild('ChrgsInf');
            $ci->addChild('Amt', number_format($fee, 2, '.', ''))->addAttribute('Ccy', $currency);
            $ci->addChild('Agt')->addChild('FinInstnId')->addChild('BICFI', $this->bic($sourceInst));
        }

        $this->addParty($tx, 'Dbtr', $leg['identifier'] ?? null, $payload['source_identifier'] ?? null,
                        $payload['source_identifier_type'] ?? 'auto', $payload['source_name'] ?? null);

        $tx->addChild('DbtrAgt')->addChild('FinInstnId')->addChild('BICFI', $this->bic($sourceInst));
        $tx->addChild('CdtrAgt')->addChild('FinInstnId')->addChild('BICFI', $this->bic($destInst));

        $cdtr = $tx->addChild('Cdtr');
        if (!empty($payload['beneficiary_name'])) {
            $cdtr->addChild('Nm', $payload['beneficiary_name']);
        }
        if (!empty($payload['beneficiary_phone'])) {
            $cdtr->addChild('CtctDtls')->addChild('MobNb', $this->e164($payload['beneficiary_phone']));
        }

        $dest = $payload['destination_identifier'] ?? $payload['destination_account'] ?? null;
        if ($dest !== null) {
            $tx->addChild('CdtrAcct')->addChild('Id')->addChild('Othr')->addChild('Id', $dest);
        }

        $tx->addChild('RmtInf')->addChild('Strd')->addChild('CdtrRefInf')->addChild('Ref', $executionRef);

        if ($legCount > 1) {
            $env = $tx->addChild('SplmtryData');
            $env->addChild('PlcAndNm', 'VM/MultiSource');
            $ms = $env->addChild('Envlp')->addChild('VMMultiSource');
            $ms->addChild('ExecutionRef', $executionRef);
            $ms->addChild('ContributionIndex', (string) $legIndex);
            $ms->addChild('ContributionCount', (string) $legCount);
            $ms->addChild('GrossAmt', number_format($gross, 2, '.', ''))->addAttribute('Ccy', $currency);
            $ms->addChild('FeeEvent', number_format($fee, 2, '.', ''))->addAttribute('Ccy', $currency);
            $ms->addChild('HoldRef', (string) ($leg['hold_reference'] ?? ''));
            $ms->addChild('SettlesVia', ($leg['asset_type'] ?? '') === 'CARD' ? 'CARD_SCHEME' : 'INTERBANK');
        }

        return $this->pretty($x);
    }

    /** MULTI_SOURCE -> one pacs.008 per source. */
    public function toPacs008Set(array $payload): array
    {
        $sources = $payload['sources'] ?? throw new RuntimeException('sources required');
        $count   = count($sources);
        $out     = [];

        foreach (array_values($sources) as $i => $leg) {
            $out[] = $this->toPacs008($payload, $i + 1, $count, $leg);
        }

        return $out;
    }

    /**
     * Debit request -> pain.013 CreditorPaymentActivationRequest.
     * The source institution executes its own pacs.008 in response, so the
     * source remains the party authorising the debit.
     */
    public function toPain013(array $payload, string $sourceInst, float $amount, ?string $holdRef = null): string
    {
        $executionRef = $payload['reference'] ?? $payload['swap_reference'];
        $currency     = $payload['currency'] ?? 'BWP';

        $x = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.013.001.09"/>'
        );

        $doc = $x->addChild('CdtrPmtActvtnReq');
        $hdr = $doc->addChild('GrpHdr');
        $hdr->addChild('MsgId', $executionRef . '-ACT');
        $hdr->addChild('CreDtTm', date('c'));
        $hdr->addChild('NbOfTxs', '1');
        $hdr->addChild('InitgPty')->addChild('Nm', 'VOUCHMORPH');

        $inf = $doc->addChild('PmtInf');
        $inf->addChild('PmtInfId', $executionRef);
        $inf->addChild('PmtMtd', 'TRF');
        $inf->addChild('ReqdExctnDt')->addChild('Dt', date('Y-m-d'));
        $inf->addChild('Dbtr')->addChild('Nm', $sourceInst);
        $inf->addChild('DbtrAgt')->addChild('FinInstnId')->addChild('BICFI', $this->bic($sourceInst));

        $tx = $inf->addChild('CdtTrfTx');
        $pid = $tx->addChild('PmtId');
        $pid->addChild('InstrId', $this->legRef($executionRef, 1));
        $pid->addChild('EndToEndId', $executionRef);

        $tx->addChild('Amt')->addChild('InstdAmt', number_format($amount, 2, '.', ''))
           ->addAttribute('Ccy', $currency);

        if ($holdRef !== null) {
            $env = $tx->addChild('SplmtryData');
            $env->addChild('PlcAndNm', 'VM/Hold');
            $env->addChild('Envlp')->addChild('HoldRef', $holdRef);
        }

        return $this->pretty($x);
    }

    /**
     * Hold release or pre-execution cancellation -> camt.056.
     * Post-execution return is pacs.004 instead.
     */
    public function toCamt056(string $executionRef, string $legRef, string $reasonCode = 'DUPL'): string
    {
        $x = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.056.001.09"/>'
        );

        $doc = $x->addChild('FIToFIPmtCxlReq');
        $a = $doc->addChild('Assgnmt');
        $a->addChild('Id', $executionRef . '-CXL');
        $a->addChild('CreDtTm', date('c'));

        $tx = $doc->addChild('Undrlyg')->addChild('TxInf');
        $tx->addChild('OrgnlEndToEndId', $executionRef);
        $tx->addChild('OrgnlInstrId', $legRef);
        $tx->addChild('CxlRsnInf')->addChild('Rsn')->addChild('Cd', $reasonCode);

        return $this->pretty($x);
    }

    /**
     * Split-tender credit advice -> camt.054.
     * ONE entry, N transaction details. Three entries would imply three credits.
     */
    public function toCamt054(array $payload, array $legs, float $creditTotal): string
    {
        $executionRef = $payload['reference'];
        $currency     = $payload['currency'] ?? 'BWP';

        $x = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.054.001.09"/>'
        );

        $doc = $x->addChild('BkToCstmrDbtCdtNtfctn');
        $hdr = $doc->addChild('GrpHdr');
        $hdr->addChild('MsgId', $executionRef . '-NTF');
        $hdr->addChild('CreDtTm', date('c'));

        $n = $doc->addChild('Ntfctn');
        $n->addChild('Id', $executionRef . '-N1');
        $n->addChild('CreDtTm', date('c'));

        $entry = $n->addChild('Ntry');
        $entry->addChild('Amt', number_format($creditTotal, 2, '.', ''))->addAttribute('Ccy', $currency);
        $entry->addChild('CdtDbtInd', 'CRDT');
        $entry->addChild('Sts')->addChild('Cd', 'BOOK');

        $dtls = $entry->addChild('NtryDtls');

        foreach (array_values($legs) as $i => $leg) {
            $net = round((float) $leg['amount'] - (float) ($leg['fee_event'] ?? 0), 2);

            $t = $dtls->addChild('TxDtls');
            $refs = $t->addChild('Refs');
            $refs->addChild('EndToEndId', $executionRef);
            $refs->addChild('InstrId', $this->legRef($executionRef, $i + 1));
            $t->addChild('Amt', number_format($net, 2, '.', ''))->addAttribute('Ccy', $currency);
            $t->addChild('RltdAgts')->addChild('DbtrAgt')->addChild('FinInstnId')
              ->addChild('BICFI', $this->bic($leg['institution']));
        }

        return $this->pretty($x);
    }

    // =================================================================
    // ISO 8583
    // =================================================================

    /** Hold on a card source -> 0100 pre-authorisation. */
    public function toIso8583PreAuth(array $payload, array $leg, string $stan): array
    {
        return $this->iso8583Base('0100', $payload, $leg, $stan, '000000');
    }

    /** Cash advance / code verification at an ATM -> 0100. */
    public function toIso8583CashAdvance(array $payload, array $leg, string $stan): array
    {
        $f = $this->iso8583Base('0100', $payload, $leg, $stan, '010000');
        $f[18] = '6011';                                   // ATM
        $f[48] = ($payload['reference'] ?? '') . '|' . ($payload['code'] ?? '');
        return $f;
    }

    /** Capture after the hold -> 0200 financial request. */
    public function toIso8583Capture(array $payload, array $leg, string $stan, string $rrn, string $authCode): array
    {
        $f = $this->iso8583Base('0200', $payload, $leg, $stan, '000000');
        $f[37] = $rrn;       // echo the pre-auth RRN
        $f[38] = $authCode;  // echo the pre-auth code
        return $f;
    }

    /** Reversal -> 0400, carrying DE 90 original data elements. */
    public function toIso8583Reversal(array $original, string $reasonCode = '4021'): array
    {
        $f = $original;
        $f[0]  = '0400';
        $f[90] = $this->de90($original);
        $f[39] = $reasonCode;
        return $f;
    }

    private function iso8583Base(string $mti, array $payload, array $leg, string $stan, string $procCode): array
    {
        $currency = $leg['currency'] ?? $payload['currency'] ?? 'BWP';
        $inst     = $leg['institution'] ?? $payload['from_institution'] ?? '';
        $amount   = (float) ($leg['amount'] ?? $payload['amount'] ?? 0);

        return [
            0  => $mti,
            2  => $leg['pan'] ?? null,
            3  => $procCode,
            4  => $this->minorUnits($amount),
            7  => gmdate('mdHis'),
            11 => str_pad($stan, 6, '0', STR_PAD_LEFT),
            12 => date('His'),
            13 => date('md'),
            18 => '6012',
            22 => $leg['pos_entry_mode'] ?? '012',
            32 => $this->acquirerId($inst),
            37 => $this->rrn($payload['reference'] ?? '', $stan),
            41 => $payload['terminal_id']   ?? 'VMORPH01',
            42 => $payload['merchant_id']   ?? 'VOUCHMORPH0001',
            43 => 'VOUCHMORPH        GABORONE     BW',
            48 => ($payload['reference'] ?? '') . '|' . $this->legRef($payload['reference'] ?? '', 1),
            49 => self::CCY_NUMERIC[$currency] ?? '072',
            63 => $payload['signature'] ?? null,
        ];
    }

    /** DE 90, 42n: original MTI, STAN, date/time, acquirer, forwarder. */
    private function de90(array $o): string
    {
        return str_pad((string) $o[0], 4, '0', STR_PAD_LEFT)
             . str_pad((string) $o[11], 6, '0', STR_PAD_LEFT)
             . str_pad((string) $o[7], 10, '0', STR_PAD_LEFT)
             . str_pad((string) ($o[32] ?? ''), 11, '0', STR_PAD_LEFT)
             . str_pad('', 11, '0');
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function addParty(
        \SimpleXMLElement $tx, string $tag, ?string $legId, ?string $payloadId, string $type, ?string $name
    ): void {
        $party = $tx->addChild($tag);
        if ($name !== null) {
            $party->addChild('Nm', $name);
        }

        $id = $legId ?? $payloadId;
        if ($id === null) {
            return;
        }

        match ($type) {
            'phone' => $party->addChild('CtctDtls')->addChild('MobNb', $this->e164($id)),
            'national_id' => (function () use ($party, $id) {
                $o = $party->addChild('Id')->addChild('PrvtId')->addChild('Othr');
                $o->addChild('Id', $id);
                $o->addChild('SchmeNm')->addChild('Prtry', 'NIDN');
            })(),
            'email' => $party->addChild('CtctDtls')->addChild('EmailAdr', $id),
            default => $tx->addChild($tag . 'Acct')->addChild('Id')->addChild('Othr')->addChild('Id', $id),
        };
    }

    private function bic(string $institution): string
    {
        $bic = $this->participants[strtolower($institution)]['bic']
            ?? $this->participants[$institution]['bic']
            ?? null;

        if ($bic === null) {
            throw new RuntimeException("no BIC configured for institution {$institution}");
        }

        return $bic;
    }

    private function acquirerId(string $institution): string
    {
        return $this->participants[strtolower($institution)]['acquirer_id'] ?? '00000000000';
    }

    private function legRef(string $executionRef, int $index): string
    {
        return $executionRef . '-L' . str_pad((string) $index, 2, '0', STR_PAD_LEFT);
    }

    private function rrn(string $executionRef, string $stan): string
    {
        return substr(gmdate('ymd') . str_pad($stan, 6, '0', STR_PAD_LEFT)
            . substr(preg_replace('/\D/', '', $executionRef), -3), 0, 12);
    }

    private function minorUnits(float $amount): string
    {
        return str_pad((string) (int) round($amount * 100), 12, '0', STR_PAD_LEFT);
    }

    private function e164(string $msisdn): string
    {
        $digits = preg_replace('/\D/', '', $msisdn);
        return '+' . $digits;
    }

    private function uetr(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function pretty(\SimpleXMLElement $x): string
    {
        $d = new \DOMDocument('1.0', 'UTF-8');
        $d->preserveWhiteSpace = false;
        $d->formatOutput = true;
        $d->loadXML($x->asXML());
        return $d->saveXML();
    }
}
