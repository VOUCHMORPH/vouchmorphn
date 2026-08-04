<?php
// Core/Config/Countries/Angola/routing_policy.php
// EXAMPLE SHAPE ONLY - do not treat these values as confirmed until
// verified against EMIS/BNA documentation or sandbox behavior.
return [
    'participant_rails' => [
        'BFA' => ['DIRECT', 'KWIK', 'MULTICAIXA'],
        'BAI' => ['DIRECT', 'KWIK', 'MULTICAIXA'],
        'SMALL_MFI' => ['DIRECT'],
    ],

    'participant_ops' => [
        'BFA' => ['deposit' => true, 'cashout' => true, 'reservation' => true],
        'BAI' => ['deposit' => true, 'cashout' => true, 'reservation' => true],
        'SMALL_MFI' => ['deposit' => true, 'cashout' => false, 'reservation' => true],
    ],

    'operation_policy' => [
        'DEPOSIT' => ['preferred' => ['KWIK', 'DIRECT'], 'reservation' => false],
        'CASHOUT' => ['preferred' => ['MULTICAIXA', 'DIRECT'], 'reservation' => true],
        'VOUCHER' => ['preferred' => ['DIRECT'], 'reservation' => true],
        'IDENTITY' => ['preferred' => ['DIRECT'], 'reservation' => true],
        'STANDARD' => ['preferred' => ['KWIK', 'DIRECT'], 'reservation' => false],
    ],
];
