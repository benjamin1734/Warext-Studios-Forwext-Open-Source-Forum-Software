<?php

declare(strict_types=1);

namespace Forwext\Core\Mail;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\User\EmailAddress;

final class MailMessageFormatter
{
    /** @return array{subject:string,headers:string,body:string} */
    public static function forPhpMail(
        MailMessage $message,
        EmailAddress $from,
        string $fromName,
    ): array {
        [$contentHeaders, $body] = self::content($message);
        $headers = [
            'Date: ' . (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_RFC2822),
            'From: ' . self::mailbox($from, $fromName),
            'MIME-Version: 1.0',
            'Auto-Submitted: auto-generated',
            'X-Auto-Response-Suppress: All',
            ...$contentHeaders,
        ];

        return [
            'subject' => self::encodedHeader($message->subject),
            'headers' => implode("\r\n", $headers),
            'body' => $body,
        ];
    }

    public static function forSmtp(
        MailMessage $message,
        EmailAddress $from,
        string $fromName,
    ): string {
        $parts = self::forPhpMail($message, $from, $fromName);
        return 'To: <' . $message->recipient->value() . ">\r\n"
            . 'Subject: ' . $parts['subject'] . "\r\n"
            . $parts['headers'] . "\r\n\r\n"
            . $parts['body'];
    }

    /** @return array{list<string>,string} */
    private static function content(MailMessage $message): array
    {
        if ($message->htmlBody === null) {
            return [[
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
            ], self::base64Body($message->textBody)];
        }

        $boundary = 'forwext_' . substr(hash('sha256', $message->subject . "\0" . $message->textBody), 0, 32);
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . self::base64Body($message->textBody) . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . self::base64Body($message->htmlBody) . "\r\n"
            . '--' . $boundary . "--\r\n";

        return [[
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ], $body];
    }

    private static function mailbox(EmailAddress $address, string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '<' . $address->value() . '>';
        }
        if (strlen($name) > 120 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new MailException('Mail sender name is invalid.');
        }

        return self::encodedHeader($name) . ' <' . $address->value() . '>';
    }

    private static function encodedHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]+$/D', $value) === 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private static function base64Body(string $value): string
    {
        return rtrim(chunk_split(base64_encode($value), 76, "\r\n"));
    }
}
