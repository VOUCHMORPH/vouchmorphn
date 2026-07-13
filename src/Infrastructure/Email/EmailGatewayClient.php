<?php
declare(strict_types=1);

namespace Infrastructure\Email;

use Infrastructure\Email\Contracts\EmailProviderInterface;
use Throwable;

/**
 * EmailGatewayClient
 * ------------------------------------------------------------------
 * Sends transactional email (OTPs, registration confirmations, etc.)
 * through a real SMTP relay via PHPMailer.
 *
 * WHY THIS EXISTS:
 * PHP's built-in mail() has no reliable delivery guarantee on most
 * containerized hosts (Railway, Heroku, etc.) — no configured local MTA,
 * no bounce handling, no deliverability tracking, and mail sent through
 * it lands in spam far more often than not. For an OTP flow, a silently
 * undelivered email means a locked-out user — unacceptable for a
 * platform whose entire pitch is reaching people other systems can't.
 *
 * SETUP REQUIRED:
 *   1. composer require phpmailer/phpmailer
 *   2. Set these environment variables (or pass equivalents via $config):
 *        SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD, SMTP_ENCRYPTION,
 *        SMTP_FROM_EMAIL, SMTP_FROM_NAME
 *      Any transactional email provider works here (SendGrid, Mailgun,
 *      Postmark, AWS SES all expose a standard SMTP relay) — this class
 *      does not lock you into one vendor.
 *
 * This class fails LOUDLY, on purpose, if it isn't configured or PHPMailer
 * isn't installed — it will not silently fall back to mail() and pretend
 * an OTP was sent when it wasn't.
 */
class EmailGatewayClient implements EmailProviderInterface
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $encryption; // 'tls' or 'ssl'
    private string $fromEmail;
    private string $fromName;
    private $logger;

    public function __construct(array $config = [], $logger = null)
    {
        $this->host       = (string)($config['smtp_host']     ?? getenv('SMTP_HOST')     ?: '');
        $this->port       = (int)   ($config['smtp_port']     ?? getenv('SMTP_PORT')     ?: 587);
        $this->username   = (string)($config['smtp_username'] ?? getenv('SMTP_USERNAME') ?: '');
        $this->password   = (string)($config['smtp_password'] ?? getenv('SMTP_PASSWORD') ?: '');
        $this->encryption = strtolower((string)($config['smtp_encryption'] ?? getenv('SMTP_ENCRYPTION') ?: 'tls'));
        $this->fromEmail  = (string)($config['from_email'] ?? getenv('SMTP_FROM_EMAIL') ?: 'noreply@vouchmorph.com');
        $this->fromName   = (string)($config['from_name']  ?? getenv('SMTP_FROM_NAME')  ?: 'VouchMorph');
        $this->logger     = $logger;

        if (!$this->isConfigured()) {
            $this->log('warning', 'EmailGatewayClient created without full SMTP configuration (host/username/password) — sends will fail until this is fixed');
        }
    }

    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->username !== '' && $this->password !== '';
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function sendEmail(string $to, string $subject, string $htmlBody): array
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->log('error', "Refusing to send — invalid recipient address: {$to}");
            return ['success' => false, 'message' => 'Invalid recipient email address'];
        }

        if (!$this->isConfigured()) {
            $this->log('error', "Cannot send email to {$to} — SMTP_HOST/SMTP_USERNAME/SMTP_PASSWORD not configured");
            return ['success' => false, 'message' => 'Email service is not configured. Contact support.'];
        }

        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            $this->log('error', 'PHPMailer is not installed — run: composer require phpmailer/phpmailer');
            return ['success' => false, 'message' => 'Email library not installed on server'];
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $this->host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->username;
            $mail->Password   = $this->password;
            $mail->SMTPSecure = $this->encryption === 'ssl'
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $this->port;
            $mail->Timeout    = 15;

            $mail->setFrom($this->fromEmail, $this->fromName);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)));

            $mail->send();
            $this->log('info', "Email sent successfully to {$to} (subject: {$subject})");
            return ['success' => true, 'message' => 'Email sent'];

        } catch (Throwable $e) {
            $errorInfo = $mail->ErrorInfo ?: $e->getMessage();
            $this->log('error', "Email send FAILED to {$to}: {$errorInfo}");
            return ['success' => false, 'message' => 'Failed to send email — please try again or use a different verification method'];
        }
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger !== null && method_exists($this->logger, $level)) {
            $this->logger->{$level}($message);
            return;
        }
        error_log("[EmailGatewayClient][" . strtoupper($level) . "] {$message}");
    }
}
