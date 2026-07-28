<?php

declare(strict_types=1);

namespace Infrastructure\Mojaloop;

/**
 * Validates the mandatory FSPIOP headers on incoming Mojaloop-style
 * requests. Bootstrap/autoload must already be loaded by whatever
 * entry point calls this - a class file must never require another
 * file before its own declare()/namespace statement, since PHP
 * requires those to be the very first statement in the script. The
 * previous version of this file did exactly that and would fatal on
 * every load.
 */
class FspiopHeaderValidator
{
    public static function validate(): void
    {
        $required = [
            'HTTP_FSPIOP_SOURCE',
            'HTTP_FSPIOP_DESTINATION',
            'HTTP_FSPIOP_SIGNATURE',
            'HTTP_DATE',
        ];

        foreach ($required as $header) {
            if (!isset($_SERVER[$header])) {
                self::reject("Missing header: {$header}");
            }
        }

        // Reject replay attacks (request older than 60 seconds)
        $date = strtotime((string)$_SERVER['HTTP_DATE']);
        if ($date === false || abs(time() - $date) > 60) {
            self::reject('Expired or invalid Date header');
        }
    }

    private static function reject(string $message): void
    {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'errorInformation' => [
                'errorCode' => '3100',
                'errorDescription' => $message,
            ],
        ]);
        exit;
    }
}
