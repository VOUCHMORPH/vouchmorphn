<?php
// Core/Config/Countries/Botswana/routing_policy.php

return [
    'participant_rails' => [
        'ZURUBANK'     => ['DIRECT'],
        'SACCUSSALIS'  => ['DIRECT'],
        'CAZACOM'      => ['DIRECT', 'CENTRALSWITCH'],
        'ABSA'         => ['DIRECT', 'CENTRALSWITCH'],
        'MTN'          => ['DIRECT', 'CENTRALSWITCH'],
    ],

    'participant_ops' => [
        'ZURUBANK'     => ['deposit' => true, 'cashout' => true,  'reservation' => true],
        'SACCUSSALIS'  => ['deposit' => true, 'cashout' => true,  'reservation' => true],
        'CAZACOM'      => ['deposit' => true, 'cashout' => false, 'reservation' => true],
        'ABSA'         => ['deposit' => true, 'cashout' => true,  'reservation' => true],
        'MTN'          => ['deposit' => true, 'cashout' => false, 'reservation' => true],
    ],

    'operation_policy' => [
        'DEPOSIT'  => ['preferred' => ['CENTRALSWITCH', 'DIRECT'], 'reservation' => false],
        'CASHOUT'  => ['preferred' => ['DIRECT'], 'reservation' => true],
        'VOUCHER'  => ['preferred' => ['DIRECT'], 'reservation' => true],
        'IDENTITY' => ['preferred' => ['DIRECT'], 'reservation' => true],
        'STANDARD' => ['preferred' => ['CENTRALSWITCH', 'DIRECT'], 'reservation' => false],
    ],
];
