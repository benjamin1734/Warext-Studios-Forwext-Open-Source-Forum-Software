<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Appearance\Guide;

use InvalidArgumentException;

final readonly class AppearancePreset
{
    /**
     * @param list<string> $changes
     * @param array<string,string> $previewVariables
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public array $changes,
        public array $previewVariables,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{1,31}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Appearance preset key is invalid.');
        }
        if ($this->label === '' || strlen($this->label) > 80) {
            throw new InvalidArgumentException('Appearance preset label is invalid.');
        }
        if ($this->description === '' || strlen($this->description) > 360) {
            throw new InvalidArgumentException('Appearance preset description is invalid.');
        }
        if ($this->changes === [] || count($this->changes) > 12) {
            throw new InvalidArgumentException('Appearance preset change list is invalid.');
        }
        foreach ($this->changes as $change) {
            if (!is_string($change) || $change === '' || strlen($change) > 180) {
                throw new InvalidArgumentException('Appearance preset change is invalid.');
            }
        }

        $allowedVariables = [
            '--guide-gap',
            '--guide-radius',
            '--guide-card-padding',
            '--guide-sidebar-width',
            '--guide-heading-scale',
        ];
        if (array_keys($this->previewVariables) !== $allowedVariables) {
            throw new InvalidArgumentException('Appearance preset preview variables are incomplete or out of order.');
        }

        $patterns = [
            '--guide-gap' => '/^(?:10|16|22)px$/D',
            '--guide-radius' => '/^(?:8|12|18)px$/D',
            '--guide-card-padding' => '/^(?:12|16|22)px$/D',
            '--guide-sidebar-width' => '/^(?:220|280|340)px$/D',
            '--guide-heading-scale' => '/^(?:0\.94|1|1\.08)$/D',
        ];
        foreach ($this->previewVariables as $key => $value) {
            if (!is_string($value) || preg_match($patterns[$key], $value) !== 1) {
                throw new InvalidArgumentException('Appearance preset preview variable is invalid.');
            }
        }
    }

    public static function balanced(): self
    {
        return new self(
            'balanced',
            'Dengeli',
            'İlk kurulum için güvenli varsayılan. Okunabilir boşluklar, dengeli kart yapısı ve standart içerik yoğunluğu.',
            [
                'Standart içerik yoğunluğu ve 16 px ana aralık.',
                '12 px köşe yuvarlama ile sade kart görünümü.',
                '280 px yan alan önerisi; mobilde tek sütuna düşer.',
                'Hareket ve görünürlük ayarları mevcut erişilebilirlik kurallarını korur.',
            ],
            [
                '--guide-gap' => '16px',
                '--guide-radius' => '12px',
                '--guide-card-padding' => '16px',
                '--guide-sidebar-width' => '280px',
                '--guide-heading-scale' => '1',
            ],
        );
    }

    public static function compact(): self
    {
        return new self(
            'compact',
            'Kompakt',
            'Yoğun forumlar ve yönetim ekranları için daha sıkı aralıklarla daha fazla içeriği aynı görünümde tutar.',
            [
                'Ana aralık 10 px olur.',
                'Kart iç boşluğu 12 px ve köşe yarıçapı 8 px olur.',
                'Yan alan 220 px önerilir.',
                'Temel erişilebilirlik ve reduced-motion davranışı değişmez.',
            ],
            [
                '--guide-gap' => '10px',
                '--guide-radius' => '8px',
                '--guide-card-padding' => '12px',
                '--guide-sidebar-width' => '220px',
                '--guide-heading-scale' => '0.94',
            ],
        );
    }

    public static function showcase(): self
    {
        return new self(
            'showcase',
            'Vitrin',
            'Portfolyo ve topluluk keşfi gibi görsel alanlarda daha ferah kartlar ve daha belirgin başlık hiyerarşisi önerir.',
            [
                'Ana aralık 22 px olur.',
                'Kart iç boşluğu 22 px ve köşe yarıçapı 18 px olur.',
                'Yan alan 340 px önerilir.',
                'Başlık ölçeği yükseltilir; mobil görünüm yine tek sütuna düşer.',
            ],
            [
                '--guide-gap' => '22px',
                '--guide-radius' => '18px',
                '--guide-card-padding' => '22px',
                '--guide-sidebar-width' => '340px',
                '--guide-heading-scale' => '1.08',
            ],
        );
    }
}
