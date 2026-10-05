<?php

declare(strict_types=1);

namespace Forwext\Core\Seo\Discovery;

final readonly class StaticPublicDiscoverySource implements PublicDiscoverySource
{
    public function sitemapEntries(int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $entries = [
            new PublicDiscoveryEntry('/', 'Forwext', 'Forwext — Open Source Forum Platform'),
            new PublicDiscoveryEntry('/members', 'Üyeler', 'Herkese açık Forwext üye dizini'),
            new PublicDiscoveryEntry('/help', 'Yardım', 'Forwext yardım, destek ve herkese açık referans merkezi'),
            new PublicDiscoveryEntry('/help/contact', 'İletişim ve destek', 'Forwext destek ve başvuru kanalları'),
            new PublicDiscoveryEntry('/help/terms', 'Kullanım koşulları', 'Forwext varsayılan kullanım koşulları'),
            new PublicDiscoveryEntry('/help/privacy', 'Gizlilik', 'Forwext gizlilik ve veri işleme referansı'),
            new PublicDiscoveryEntry('/help/cookies', 'Çerez kullanımı', 'Forwext oturum ve güvenlik çerezleri referansı'),
            new PublicDiscoveryEntry('/help/bb-codes', 'BB kodları', 'Forwext editör biçimlendirme referansı'),
            new PublicDiscoveryEntry('/help/smilies', 'Emoji ve ifadeler', 'Forwext emoji kataloğu'),
            new PublicDiscoveryEntry('/help/trophies', 'Kupalar ve rozetler', 'Forwext herkese açık başarım kataloğu'),
            new PublicDiscoveryEntry('/help/rss', 'RSS ve Atom', 'Forwext herkese açık yayın akışları'),
        ];

        return array_slice($entries, 0, $limit);
    }

    public function feedEntries(int $limit): array
    {
        return [];
    }
}
