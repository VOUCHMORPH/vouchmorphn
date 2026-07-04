<?php
// src/Core/Config/channel_adapters.php
// Mirrors the shape of message_adapters.php but for channel (not rail) adapters.

return [
    'ussd_gateways' => [
        'AFRICASTALKING_STYLE' => \Infrastructure\USSD\Contracts\UssdGatewayAdapter::class,
        'TELCO_XML_STYLE' => \Infrastructure\USSD\Contracts\UssdGatewayAdapter::class,
        'JSON_ENVELOPE_STYLE' => \Infrastructure\USSD\Contracts\UssdGatewayAdapter::class,
    ],

    'qr_adapters' => [
        'EMVCO' => \Infrastructure\QRcodes\EmvQrAdapter::class,
        // 'MOJALOOP' => \Infrastructure\Channels\Qr\MojaloopQrAdapter::class,   // add when needed
        // 'PROPRIETARY_MPESA' => \Infrastructure\Channels\Qr\MpesaQrAdapter::class,
    ],

    'transaction_adapters' => [
        'ISO8583' => \Infrastructure\Transactions\Contracts\Iso8583TransactionAdapter::class,
        'MOBILE_MONEY' => \Infrastructure\Transactions\Contracts\MobileMoneyTransactionAdapter::class,
        'REST_GENERIC' => \Infrastructure\Transactions\Contracts\RestTransactionAdapter::class,
    ],
];
