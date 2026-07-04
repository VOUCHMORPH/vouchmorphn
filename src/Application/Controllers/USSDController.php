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
    // ... $participants, $participantsByWalletType, $flows as before ...

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
     * ---- FIX 2: entrypoint now goes through the gateway adapter instead
     * of hand-parsing sessionId/phoneNumber/text directly. This is what
     * makes the controller gateway-agnostic - swap the gatewayKey and any
     * USSD aggregator works without touching this class again.
     */
    public function handleUSSDRequest(array $rawRequest, string $gatewayKey = 'AFRICASTALKING_STYLE'): string
    {
        $gateway = $this->channelFactory->getUssdGatewayAdapter($gatewayKey);
        $sessionRequest = $gateway->parseRequest($rawRequest);

        $response = $this->processSession($sessionRequest); // your existing menu logic, adapted to return UssdSessionResponse

        return $gateway->formatResponse($response);
        // Note: also send $gateway->getResponseContentType() as the Content-Type header
        // in the entrypoint script (ussd/index.php), not inside this method.
    }

    /**
     * Existing showMainMenu/processMenuLevel/handleQuickSwap/etc all stay,
     * just wrap their final "CON ..."/"END ..." string returns as:
     *   return UssdSessionResponse::continue($menuText);   // instead of "CON {$menuText}"
     *   return UssdSessionResponse::end($menuText);        // instead of "END {$menuText}"
     * strip the literal "CON "/"END " prefixes from the existing strings -
     * the gateway adapter adds them back in formatResponse().
     */
    private function processSession(UssdSessionRequest $req): UssdSessionResponse
    {
        // same body as old handleUSSDRequest(), using $req->sessionId / $req->phoneNumber / $req->text
        // instead of pulling those out of $request directly.
        // ...
        return UssdSessionResponse::end('placeholder - wire up your existing menu tree here');
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
