<?php

namespace Domain\Identity;

/**
 * A reason ContactVerificationService refused a step. The message is
 * written for the account holder and is safe to show them as-is.
 */
final class ContactVerificationException extends \RuntimeException
{
}
