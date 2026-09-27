<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Delivery;

use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Mail\MailException;
use Forwext\Core\Mail\MailMessage;
use Forwext\Core\Mail\MailTransport;
use InvalidArgumentException;

final readonly class MailAuthLinkDelivery implements AuthLinkDelivery
{
    public function __construct(
        private MailTransport $mail,
        private string $canonicalUrl,
        private string $siteName,
    ) {
        if (filter_var($canonicalUrl, FILTER_VALIDATE_URL) === false
            || !in_array(parse_url($canonicalUrl, PHP_URL_SCHEME), ['http', 'https'], true)
        ) {
            throw new InvalidArgumentException('Canonical URL is invalid for authentication mail.');
        }
        if ($siteName === '' || strlen($siteName) > 120 || preg_match('/[\x00-\x1F\x7F]/', $siteName) === 1) {
            throw new InvalidArgumentException('Site name is invalid for authentication mail.');
        }
    }

    public function available(): bool
    {
        return $this->mail->available();
    }

    public function sendEmailVerification(EmailAddress $recipient, string $token): bool
    {
        if (!$this->validToken($token)) {
            return false;
        }

        return $this->send(
            $recipient,
            'E-posta adresini doğrula',
            '/verify-email?token=' . rawurlencode($token),
            'Forwext hesabının e-posta adresini doğrulamak için aşağıdaki bağlantıyı kullan.',
        );
    }

    public function sendPasswordReset(EmailAddress $recipient, string $token): bool
    {
        if (!$this->validToken($token)) {
            return false;
        }

        return $this->send(
            $recipient,
            'Parolanı sıfırla',
            '/reset-password?token=' . rawurlencode($token),
            'Forwext hesabının parolasını sıfırlamak için aşağıdaki bağlantıyı kullan. Bu isteği sen yapmadıysan e-postayı yok sayabilirsin.',
        );
    }

    private function validToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) === 1;
    }

    private function send(
        EmailAddress $recipient,
        string $subject,
        string $path,
        string $lead,
    ): bool {
        $url = rtrim($this->canonicalUrl, '/') . $path;
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSite = htmlspecialchars($this->siteName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLead = htmlspecialchars($lead, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        try {
            $this->mail->send(new MailMessage(
                $recipient,
                $this->siteName . ' — ' . $subject,
                $lead . "\n\n" . $url . "\n\nBağlantıyı yalnızca " . $this->siteName . ' alan adı üzerinden kullan.',
                '<!doctype html><html><body><p>' . $safeLead . '</p><p><a href="' . $safeUrl
                    . '">' . htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '</a></p><p>Bağlantıyı yalnızca ' . $safeSite . ' alan adı üzerinden kullan.</p></body></html>',
            ));
            return true;
        } catch (MailException|InvalidArgumentException) {
            return false;
        }
    }
}
