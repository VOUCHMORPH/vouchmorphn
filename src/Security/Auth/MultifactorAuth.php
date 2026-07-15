<?php

namespace Security\Auth;

use Infrastructure\SMS\SmsNotificationService;

class MultifactorAuth
{
    private SmsNotificationService $smsService;

    public function __construct(SmsNotificationService $smsService)
    {
        $this->smsService = $smsService;
    }

    public function generateOtp(int $length = 6): string
    {
        return str_pad((string)random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    public function validateOtp(string $inputOtp, string $actualOtp): bool
    {
        return hash_equals($actualOtp, $inputOtp);
    }

    public function sendOtp(string $recipient, string $otp): bool
    {
        return $this->smsService->send($recipient, "Your VouchMorph verification code is: {$otp}");
    }
}
