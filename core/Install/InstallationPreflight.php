<?php

declare(strict_types=1);

namespace Forwext\Core\Install;

use RuntimeException;

final readonly class InstallationPreflight
{
    public function __construct(private string $projectRoot)
    {
        if ($projectRoot === '' || str_contains($projectRoot, "\0")) {
            throw new RuntimeException('Installer project root is invalid.');
        }
    }

    /**
     * @return list<array{key:string,label:string,passed:bool,required:bool,detail:string}>
     */
    public function checks(): array
    {
        return [
            $this->check('php', 'PHP 8.4+', PHP_VERSION_ID >= 80400, 'Çalışan sürüm: ' . PHP_VERSION),
            $this->check('openssl', 'OpenSSL', extension_loaded('openssl'), 'Güvenli sır saklama ve TLS için gerekli.'),
            $this->check('pdo', 'PDO', extension_loaded('pdo'), 'Veritabanı katmanı için gerekli.'),
            $this->check('pdo_mysql', 'PDO MySQL', extension_loaded('pdo_mysql'), 'MySQL/MariaDB bağlantısı için gerekli.'),
            $this->check('json', 'JSON', extension_loaded('json'), 'Konfigürasyon ve veri sözleşmeleri için gerekli.'),
            $this->check(
                'autoload',
                'Hazır production vendor',
                is_file($this->projectRoot . '/vendor/autoload.php'),
                'Composer çalıştırmadan kurulum için full ZIP içindeki vendor/autoload.php gereklidir.',
            ),
            $this->check(
                'config_writable',
                'config/ yazılabilir',
                is_dir($this->projectRoot . '/config')
                    && !is_link($this->projectRoot . '/config')
                    && is_writable($this->projectRoot . '/config'),
                'Site konfigürasyonu ve master key oluşturulabilmeli.',
            ),
            $this->check(
                'storage_writable',
                'storage/ yazılabilir',
                is_dir($this->projectRoot . '/storage')
                    && !is_link($this->projectRoot . '/storage')
                    && is_writable($this->projectRoot . '/storage'),
                'Log, cache, session, secret ve kurulum durumu yazılabilmeli.',
            ),
        ];
    }

    public function isReady(): bool
    {
        foreach ($this->checks() as $check) {
            if ($check['required'] && !$check['passed']) {
                return false;
            }
        }

        return true;
    }

    public function assertReady(): void
    {
        if (!$this->isReady()) {
            throw new RuntimeException('Required cPanel installation preflight checks are not satisfied.');
        }
    }

    /** @return array{key:string,label:string,passed:bool,required:bool,detail:string} */
    private function check(string $key, string $label, bool $passed, string $detail): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'passed' => $passed,
            'required' => true,
            'detail' => $detail,
        ];
    }
}
