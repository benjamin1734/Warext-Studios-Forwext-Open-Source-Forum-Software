<?php

declare(strict_types=1);

namespace Forwext\Core\Install;

use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Domain\User\UserLocale;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Domain\User\UserTimezone;
use InvalidArgumentException;
use SensitiveParameter;

final readonly class InstallationInput
{
    /** @var list<string>|null */
    public ?array $enabledModules;

    /**
     * @param list<string>|null $enabledModules
     */
    public function __construct(
        public string $canonicalUrl,
        public string $databaseHost,
        public int $databasePort,
        public string $databaseName,
        public string $databaseUsername,
        #[SensitiveParameter] public string $databasePassword,
        public string $siteName = 'Forwext',
        public string $siteDescription = '',
        public string $siteLocale = 'tr',
        public string $siteTimezone = 'UTC',
        public string $adminUsername = '',
        public string $adminEmail = '',
        #[SensitiveParameter] public string $adminPassword = '',
        public string $mailDriver = 'disabled',
        public string $mailFromAddress = '',
        public string $mailFromName = 'Forwext',
        public string $smtpHost = '',
        public int $smtpPort = 587,
        public string $smtpEncryption = 'starttls',
        public string $smtpUsername = '',
        #[SensitiveParameter] public string $smtpPassword = '',
        ?array $enabledModules = null,
        public string $themePreset = 'balanced',
    ) {
        $parts = parse_url($canonicalUrl);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
        ) {
            throw new InvalidArgumentException('Canonical URL must be an absolute HTTP(S) origin/path without credentials, query or fragment.');
        }

        if ($databasePort < 1 || $databasePort > 65535) {
            throw new InvalidArgumentException('Database port is outside the valid range.');
        }
        foreach ([$databaseHost, $databaseName, $databaseUsername] as $databaseValue) {
            if (trim($databaseValue) === '' || strlen($databaseValue) > 191 || str_contains($databaseValue, "\0")) {
                throw new InvalidArgumentException('Database connection fields are invalid.');
            }
        }

        if (trim($siteName) === '' || strlen($siteName) > 120 || preg_match('//u', $siteName) !== 1) {
            throw new InvalidArgumentException('Site name is invalid.');
        }
        if (strlen($siteDescription) > 500 || preg_match('//u', $siteDescription) !== 1) {
            throw new InvalidArgumentException('Site description is invalid.');
        }
        UserLocale::fromString($siteLocale);
        UserTimezone::fromString($siteTimezone);

        $adminFields = [$adminUsername, $adminEmail, $adminPassword];
        $adminFieldCount = count(array_filter($adminFields, static fn (string $value): bool => $value !== ''));
        if ($adminFieldCount !== 0 && $adminFieldCount !== 3) {
            throw new InvalidArgumentException('Administrator username, email and password must be supplied together.');
        }
        if ($adminFieldCount === 3) {
            Username::fromString($adminUsername);
            EmailAddress::fromString($adminEmail);
        }

        if (!in_array($mailDriver, ['disabled', 'smtp'], true)) {
            throw new InvalidArgumentException('Mail driver is invalid.');
        }
        if ($mailFromAddress !== '') {
            EmailAddress::fromString($mailFromAddress);
        }
        if (trim($mailFromName) === '' || strlen($mailFromName) > 120 || preg_match('//u', $mailFromName) !== 1) {
            throw new InvalidArgumentException('Mail sender name is invalid.');
        }
        if ($smtpPort < 1 || $smtpPort > 65535) {
            throw new InvalidArgumentException('SMTP port is outside the valid range.');
        }
        if (!in_array($smtpEncryption, ['none', 'starttls', 'tls'], true)) {
            throw new InvalidArgumentException('SMTP encryption mode is invalid.');
        }
        if ($mailDriver === 'smtp' && (trim($smtpHost) === '' || $mailFromAddress === '')) {
            throw new InvalidArgumentException('SMTP host and sender address are required when SMTP mail is enabled.');
        }
        foreach ([$smtpHost, $smtpUsername] as $smtpValue) {
            if (strlen($smtpValue) > 255 || str_contains($smtpValue, "\0")) {
                throw new InvalidArgumentException('SMTP configuration is invalid.');
            }
        }

        if (!in_array($themePreset, ['balanced', 'compact', 'showcase'], true)) {
            throw new InvalidArgumentException('Installer theme preset is invalid.');
        }

        if ($enabledModules === null) {
            $this->enabledModules = null;
        } else {
            $normalized = [];
            foreach ($enabledModules as $moduleKey) {
                if (!is_string($moduleKey)
                    || preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $moduleKey) !== 1
                ) {
                    throw new InvalidArgumentException('Installer module selection is invalid.');
                }
                $normalized[$moduleKey] = true;
            }
            $keys = array_keys($normalized);
            sort($keys, SORT_STRING);
            $this->enabledModules = $keys;
        }
    }

    public function normalizedCanonicalUrl(): string
    {
        return rtrim($this->canonicalUrl, '/');
    }

    public function relyingPartyId(): string
    {
        $host = parse_url($this->canonicalUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new InvalidArgumentException('Canonical URL hostname is unavailable.');
        }

        return strtolower($host);
    }

    public function hasAdministratorBootstrap(): bool
    {
        return $this->adminUsername !== '' && $this->adminEmail !== '' && $this->adminPassword !== '';
    }

    public function themeKey(): string
    {
        return match ($this->themePreset) {
            'compact' => 'forwext-compact',
            'showcase' => 'forwext-showcase',
            default => 'forwext-balanced',
        };
    }

    public function themeName(): string
    {
        return match ($this->themePreset) {
            'compact' => 'Forwext Kompakt',
            'showcase' => 'Forwext Vitrin',
            default => 'Forwext Dengeli',
        };
    }
}
