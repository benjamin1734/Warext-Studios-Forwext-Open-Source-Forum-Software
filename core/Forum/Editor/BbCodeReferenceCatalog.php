<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

final class BbCodeReferenceCatalog
{
    /** @return list<array{tag:string,label:string,example:string,description:string}> */
    public static function all(): array
    {
        return [
            ['tag'=>'b','label'=>'Kalın','example'=>'[b]metin[/b]','description'=>'Metni kalın vurgular.'],
            ['tag'=>'i','label'=>'İtalik','example'=>'[i]metin[/i]','description'=>'Metni italik vurgular.'],
            ['tag'=>'u','label'=>'Altı çizili','example'=>'[u]metin[/u]','description'=>'Metnin altını çizer.'],
            ['tag'=>'s','label'=>'Üstü çizili','example'=>'[s]metin[/s]','description'=>'Metnin üstünü çizer.'],
            ['tag'=>'quote','label'=>'Alıntı','example'=>'[quote=Kullanıcı]metin[/quote]','description'=>'Kaynak etiketiyle alıntı bloğu oluşturur.'],
            ['tag'=>'url','label'=>'Bağlantı','example'=>'[url=https://example.com]bağlantı[/url]','description'=>'Güvenli bağlantı politikası üzerinden URL oluşturur.'],
            ['tag'=>'code','label'=>'Kod','example'=>'[code]echo "Merhaba";[/code]','description'=>'İçeriği kod bloğu olarak gösterir.'],
            ['tag'=>'mention','label'=>'Bahsetme','example'=>'[mention=<kullanıcı-id>]','description'=>'Geçerli kullanıcı kimliği için mention üretir.'],
            ['tag'=>'emoji','label'=>'Emoji','example'=>'[emoji=smile]','description'=>'Forwext emoji kataloğundan bir emoji gösterir.'],
            ['tag'=>'embed','label'=>'Gömme','example'=>'[embed]https://example.com[/embed]','description'=>'İzin verilen sağlayıcılar için güvenli gömme çıktısı üretir.'],
        ];
    }
}
