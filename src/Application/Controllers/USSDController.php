<?php
declare(strict_types=1);

namespace Application\Controllers;

use PDO;
use Domain\Services\SwapService;
use Infrastructure\ChannelAdapterFactory;
use Infrastructure\USSD\Contracts\UssdSessionRequest;
use Infrastructure\USSD\Contracts\UssdSessionResponse;


class USSDController
{
    private PDO $db;
    private SwapService $swapService;
    private array $config;
    private ChannelAdapterFactory $channelFactory;
    private array $participants = [];

    public function __construct(array $config, PDO $db, ?object $logger = null)
    {
        $this->config = $config;
        $this->db = $db;

        $this->loadParticipants();

        // was: __construct($this->db, $config['settings'] ?? [], $config['country'] ?? 'BW',
        //                   $config['encryption_key'] ?? '', $config)   <- 5 args, wrong shape
        $this->swapService = new SwapService(
            $this->db,
            $config['settings'] ?? [],
            $config['country'] ?? 'BW',
            $logger
        );

        $this->channelFactory = new ChannelAdapterFactory($config['country'] ?? 'Botswana');
    }

    /**
     * Entrypoint goes through the gateway adapter instead of hand-parsing
     * sessionId/phoneNumber/text directly - swap the gatewayKey and any
     * USSD aggregator works without touching this class again.
     */
    public function handleUSSDRequest(array $rawRequest, string $gatewayKey = 'AFRICASTALKING_STYLE'): string
    {
        $gateway = $this->channelFactory->getUssdGatewayAdapter($gatewayKey);
        $sessionRequest = $gateway->parseRequest($rawRequest);

        $response = $this->processSession($sessionRequest);

        return $gateway->formatResponse($response);
        // Note: also send $gateway->getResponseContentType() as the Content-Type header
        // in the entrypoint script (ussd/index.php), not inside this method.
    }

    private function loadParticipants(): void
    {
        $this->participants = $this->config['participants'] ?? [];
    }

    /**
     * Menu state machine. USSD gateways replay the full input string on
     * every request (e.g. "1*2*500"), but we track progress via an
     * explicit `stage` stored in ussd_sessions rather than re-deriving it
     * from the level count, since branches (cashout vs deposit) diverge
     * in length.
     */
    private function processSession(UssdSessionRequest $req): UssdSessionResponse
    {
        $sessionId = $req->sessionId;
        $levels = $req->levels();

        if ($levels === []) {
            $this->clearSession($sessionId);
            $this->setSession($sessionId, 'stage', 'main_menu');
            $this->setSession($sessionId, 'source_phone', $req->phoneNumber);
            $this->setSession($sessionId, 'user_id', $req->phoneNumber);
            return UssdSessionResponse::continue($this->showMainMenu());
        }

        $stage = $this->getSession($sessionId, 'stage') ?? 'main_menu';
        $input = (string)end($levels);

        return $this->processMenuLevel($sessionId, $stage, $input);
    }

    private function showMainMenu(): string
    {
        return "Welcome to VouchMorph\n1. Cash Swap";
    }

    private function processMenuLevel(string $sessionId, string $stage, string $input): UssdSessionResponse
    {
        switch ($stage) {
            case 'main_menu':
                if ($input !== '1') {
                    $this->clearSession($sessionId);
                    return UssdSessionResponse::end('Invalid option.');
                }
                $list = $this->formatParticipantList();
                if ($list === null) {
                    $this->clearSession($sessionId);
                    return UssdSessionResponse::end('Service temporarily unavailable. Please try again later.');
                }
                $this->setSession($sessionId, 'stage', 'select_source');
                return UssdSessionResponse::continue("Select source institution:\n{$list}");

            case 'select_source':
                $institution = $this->resolveParticipantByIndex($input);
                if ($institution === null) {
                    return UssdSessionResponse::end('Invalid selection.');
                }
                $this->setSession($sessionId, 'source_institution', $institution);
                $this->setSession($sessionId, 'stage', 'select_source_type');
                return UssdSessionResponse::continue("Select account type:\n1. Bank Account\n2. Mobile Wallet\n3. Voucher");

            case 'select_source_type':
                $typeMap = ['1' => 'account', '2' => 'wallet', '3' => 'voucher'];
                $sourceType = $typeMap[$input] ?? null;
                if ($sourceType === null) {
                    return UssdSessionResponse::end('Invalid selection.');
                }
                $this->setSession($sessionId, 'source_type', $sourceType);
                $this->setSession($sessionId, 'stage', 'enter_identifier');
                $prompt = match ($sourceType) {
                    'account' => 'Enter your account number:',
                    'voucher' => 'Enter voucher number:',
                    default => 'Enter wallet phone number:',
                };
                return UssdSessionResponse::continue($prompt);

            case 'enter_identifier':
                $sourceType = $this->getSession($sessionId, 'source_type') ?? 'wallet';
                $field = match ($sourceType) {
                    'account' => 'account_number',
                    'voucher' => 'voucher_number',
                    default => 'source_phone',
                };
                $this->setSession($sessionId, $field, $input);
                $this->setSession($sessionId, 'stage', 'enter_amount');
                return UssdSessionResponse::continue('Enter amount to swap (BWP):');

            case 'enter_amount':
                if (!is_numeric($input) || (float)$input <= 0) {
                    return UssdSessionResponse::end('Invalid amount.');
                }
                $this->setSession($sessionId, 'amount', $input);
                $this->setSession($sessionId, 'stage', 'select_delivery_mode');
                return UssdSessionResponse::continue("How do you want to receive funds?\n1. Cash pickup (ATM code)\n2. Deposit to account");

            case 'select_delivery_mode':
                if ($input === '1') {
                    $this->setSession($sessionId, 'delivery_mode', 'cashout');
                    $this->setSession($sessionId, 'stage', 'enter_beneficiary_phone');
                    return UssdSessionResponse::continue('Enter beneficiary phone number:');
                }
                if ($input === '2') {
                    $this->setSession($sessionId, 'delivery_mode', 'deposit');
                    $list = $this->formatParticipantList();
                    if ($list === null) {
                        $this->clearSession($sessionId);
                        return UssdSessionResponse::end('Service temporarily unavailable. Please try again later.');
                    }
                    $this->setSession($sessionId, 'stage', 'select_destination');
                    return UssdSessionResponse::continue("Select destination institution:\n{$list}");
                }
                return UssdSessionResponse::end('Invalid selection.');

            case 'enter_beneficiary_phone':
                $this->setSession($sessionId, 'beneficiary_phone', $input);
                $this->setSession($sessionId, 'destination_institution', (string)$this->getSession($sessionId, 'source_institution'));
                $this->setSession($sessionId, 'stage', 'enter_pin');
                return UssdSessionResponse::continue('Enter your PIN to confirm:');

            case 'select_destination':
                $institution = $this->resolveParticipantByIndex($input);
                if ($institution === null) {
                    return UssdSessionResponse::end('Invalid selection.');
                }
                $this->setSession($sessionId, 'destination_institution', $institution);
                $this->setSession($sessionId, 'stage', 'enter_beneficiary_account');
                return UssdSessionResponse::continue('Enter destination account number:');

            case 'enter_beneficiary_account':
                $this->setSession($sessionId, 'beneficiary_account', $input);
                $this->setSession($sessionId, 'stage', 'enter_pin');
                return UssdSessionResponse::continue('Enter your PIN to confirm:');

            case 'enter_pin':
                $sourceType = $this->getSession($sessionId, 'source_type') ?? 'wallet';
                $pinField = match ($sourceType) {
                    'account' => 'wallet_pin',
                    'voucher' => 'voucher_pin',
                    default => 'ewallet_pin',
                };
                $this->setSession($sessionId, $pinField, $input);
                $amount = (float)($this->getSession($sessionId, 'amount') ?? '0');
                return $this->executeSwapFromSession($sessionId, $sourceType, $amount);

            default:
                $this->clearSession($sessionId);
                return UssdSessionResponse::end('Session expired. Please try again.');
        }
    }

    private function formatParticipantList(): ?string
    {
        $active = array_filter(
            $this->participants,
            fn($p) => strtoupper((string)($p['status'] ?? 'ACTIVE')) === 'ACTIVE'
        );
        if (empty($active)) {
            return null;
        }

        $lines = [];
        $i = 1;
        foreach ($active as $code => $participant) {
            $lines[] = $i . '. ' . ($participant['name'] ?? $code);
            $i++;
        }
        return implode("\n", $lines);
    }

    private function resolveParticipantByIndex(string $input): ?string
    {
        if (!ctype_digit($input)) {
            return null;
        }
        $active = array_filter(
            $this->participants,
            fn($p) => strtoupper((string)($p['status'] ?? 'ACTIVE')) === 'ACTIVE'
        );
        $codes = array_keys($active);
        $index = (int)$input;
        return $codes[$index - 1] ?? null;
    }

    private function getSession(string $sessionId, string $key): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT session_value FROM ussd_sessions WHERE session_id = :sid AND session_key = :key'
        );
        $stmt->execute(['sid' => $sessionId, 'key' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    private function setSession(string $sessionId, string $key, string $value): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ussd_sessions (session_id, session_key, session_value, updated_at)
             VALUES (:sid, :key, :val, NOW())
             ON CONFLICT (session_id, session_key)
             DO UPDATE SET session_value = EXCLUDED.session_value, updated_at = NOW()'
        );
        $stmt->execute(['sid' => $sessionId, 'key' => $key, 'val' => $value]);
    }

    private function clearSession(string $sessionId): void
    {
        $stmt = $this->db->prepare('DELETE FROM ussd_sessions WHERE session_id = :sid');
        $stmt->execute(['sid' => $sessionId]);
    }

    private function truncateForUssd(string $text, int $maxLen): string
    {
        return mb_strlen($text) > $maxLen ? mb_substr($text, 0, $maxLen - 3) . '...' : $text;
    }

    /**
     * ---- FIX 3: executeSwapFromSession now calls the REAL SwapService
     * method with the REAL payload shape it expects (see SwapService::
     * extractSourceInstitution/extractDestinationInstitution/executeAtomicSwap),
     * instead of the fictional executeSwap($payload) with a source/destination
     * nested shape that doesn't exist in the real class.
     */
    private function executeSwapFromSession(string $sessionId, string $sourceType, float $amount): UssdSessionResponse
    {
        $sourceInstitution      = (string)$this->getSession($sessionId, 'source_institution');
        $destinationInstitution = (string)$this->getSession($sessionId, 'destination_institution');
        $deliveryMode           = (string)$this->getSession($sessionId, 'delivery_mode'); // 'cashout'|'deposit'
        $sourcePhone            = (string)$this->getSession($sessionId, 'source_phone');
        $userId                 = (string)$this->getSession($sessionId, 'user_id');

        // Real SwapService reads: from_institution/source_institution, to_institution/destination_institution,
        // asset_type, source_identifier, amount, currency, swap_type, delivery_method,
        // destination_identifier (+ _type), beneficiary_phone, wallet_pin/pin.
        $payload = [
            'swap_type' => $deliveryMode === 'cashout' ? 'CASHOUT' : 'DEPOSIT',
            'from_institution' => $sourceInstitution,
            'source_institution' => $sourceInstitution,
            'to_institution' => $destinationInstitution,
            'destination_institution' => $destinationInstitution,
            'asset_type' => strtoupper($sourceType === 'account' ? 'ACCOUNT' : 'WALLET'),
            'amount' => $amount,
            'currency' => 'BWP',
            'delivery_method' => strtoupper($deliveryMode === 'cashout' ? 'ATM' : 'DEPOSIT'),
            'reference' => 'USSD_' . $sessionId . '_' . time(),
        ];

        // source identifier by type
        $payload['source_identifier'] = match ($sourceType) {
            'account' => (string)$this->getSession($sessionId, 'account_number'),
            'voucher' => (string)$this->getSession($sessionId, 'voucher_number'),
            'wallet' => $sourcePhone,
            'e-wallet' => $sourcePhone,
            default => $sourcePhone,
        };

        // PIN, if applicable
        foreach (['wallet_pin', 'voucher_pin', 'ewallet_pin'] as $pinField) {
            $pin = $this->getSession($sessionId, $pinField);
            if ($pin !== null && $pin !== '') {
                $payload['pin'] = $pin;
                $payload['wallet_pin'] = $pin;
                break;
            }
        }

        // destination identifier by delivery mode
        if ($deliveryMode === 'cashout') {
            $payload['beneficiary_phone'] = (string)$this->getSession($sessionId, 'beneficiary_phone');
        } else {
            $payload['destination_identifier'] = (string)$this->getSession($sessionId, 'beneficiary_account');
            $payload['destination_identifier_type'] = 'account';
        }

        try {
            // was: $this->swapService->executeSwap($payload)  <- method doesn't exist
            $result = $this->swapService->executeAtomicSwap($payload);
            $this->clearSession($sessionId);

            $status = strtolower((string)($result['status'] ?? ''));
            if ($status === 'success' || $status === 'pending_cashout') {
                $ref = $result['reference'] ?? 'N/A';
                $code = $result['atm_code'] ?? $result['swap_code'] ?? null;
                $msg = "Swap successful\nRef: {$ref}\nAmt: {$amount} BWP";
                if ($code) {
                    $msg .= "\nCode: {$code}";
                }
                return UssdSessionResponse::end($this->truncateForUssd($msg, 180));
            }

            return UssdSessionResponse::end($this->truncateForUssd($result['message'] ?? 'Swap failed', 150));
        } catch (\Throwable $e) {
            $this->clearSession($sessionId);
            return UssdSessionResponse::end('Swap failed. Please try again.');
        }
    }
}
