<?php

declare(strict_types=1);

namespace Forwext\App\Web\PublicReference;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Trophy\TrophyDefinition;

final class PublicReferenceHtml
{
    public static function index(BasePath $basePath): string
    {
        $cards = [
            ['/faq','SSS','Sık sorulan sorular ve yayınlanmış yardım makaleleri.'],
            ['/support/new','Destek','Hesap, içerik veya teknik sorun için destek talebi oluştur.'],
            ['/help/contact','İletişim','Destek kanalları ve doğru başvuru yolunu öğren.'],
            ['/help/bb-codes','BB kodları','Editörde desteklenen biçimlendirme etiketlerini görüntüle.'],
            ['/help/smilies','Emoji ve ifadeler','Desteklenen emoji anahtarlarını ve ifadeleri görüntüle.'],
            ['/help/trophies','Kupalar ve rozetler','Etkin kupa, rozet ve başarımları incele.'],
            ['/help/rss','RSS / Atom','Forwext herkese açık içerik akışlarına abone ol.'],
            ['/help/terms','Kullanım koşulları','Topluluk ve hizmet kullanımına ilişkin varsayılan koşullar.'],
            ['/help/privacy','Gizlilik','Forwext içinde işlenen veri sınıfları ve kullanıcı kontrolleri.'],
            ['/help/cookies','Çerez kullanımı','Oturum ve güvenlik amaçlı tarayıcı depolama açıklaması.'],
        ];

        $body = '<section class="public-reference discovery-page"><header class="surface-head public-reference-head"><div>'
            . '<span class="forum-eyebrow">YARDIM MERKEZİ</span><h1>Forwext yardım ve referans</h1>'
            . '<p>Destek, kullanım rehberleri, yayın akışları ve herkese açık referans belgeleri tek yerde.</p></div></header>'
            . '<div class="public-reference-grid">';
        foreach ($cards as [$path,$title,$description]) {
            $body .= '<a class="surface-panel public-reference-card" href="' . self::e($basePath->prepend($path)) . '">'
                . '<strong>' . self::e($title) . '</strong><span>' . self::e($description) . '</span></a>';
        }
        $body .= '</div></section>';

        return ProfileHtml::page('Yardım', $body, $basePath);
    }

    public static function contact(BasePath $basePath): string
    {
        return self::document(
            'İletişim ve destek',
            'İLETİŞİM',
            'Sorunun türüne göre doğru kanalı kullan.',
            [
                ['Teknik veya hesap desteği','Kişisel yardım gerektiren konularda destek talebi oluştur. '
                    . self::link($basePath, '/support/new', 'Destek talebi oluştur')],
                ['Genel bilgi','Önce yayınlanmış SSS içeriğini kontrol edebilirsin. '
                    . self::link($basePath, '/faq', 'SSS sayfasını aç')],
                ['Hata bildirimi','Yazılım hatalarını ayrı hata bildirim sistemi üzerinden gönder. '
                    . self::link($basePath, '/bugs/report', 'Hata bildir')],
            ],
            $basePath,
        );
    }

    public static function terms(BasePath $basePath): string
    {
        return self::document('Kullanım koşulları','KULLANIM KOŞULLARI',
            'Bu metin Forwext yazılımının varsayılan topluluk kullanım çerçevesidir; site işletmecisi kendi hukuki koşullarını yayınlayabilir.',
            [
                ['Hesap kullanımı','Hesabın güvenliğinden, doğru iletişim bilgilerinden ve hesabın üzerinden yapılan işlemlerden kullanıcı sorumludur.'],
                ['İçerik ve davranış','Yasa dışı içerik, spam, kötüye kullanım, kimliğe bürünme, güvenlik ihlali ve topluluğun çalışmasını engelleyen davranışlar kabul edilmez.'],
                ['Moderasyon','Yetkili ekip içerikleri inceleyebilir; topluluk kuralları doğrultusunda görünürlüğü değiştirebilir, uyarı veya erişim kısıtı uygulayabilir.'],
                ['Hizmet sürekliliği','Bakım, güvenlik veya teknik gereksinimler nedeniyle özellikler değişebilir ya da geçici olarak erişilemez olabilir.'],
                ['Yerel koşullar','Bu varsayılan metin hukuki danışmanlık değildir. Site işletmecisinin yayınladığı özel koşullar varsa onlar esas alınır.'],
            ],$basePath);
    }

    public static function privacy(BasePath $basePath): string
    {
        return self::document('Gizlilik','GİZLİLİK',
            'Forwext hesap, topluluk ve güvenlik özelliklerini çalıştırmak için gerekli veri sınıflarını işler.',
            [
                ['Hesap verileri','Kullanıcı adı, e-posta, tercih ve hesap durumları; kimlik doğrulama ve hesap yönetimi için kullanılır.'],
                ['Topluluk içeriği','Konu, mesaj, profil, portfolyo, destek ve benzeri kullanıcı içerikleri ilgili özelliği sunmak ve moderasyonu yürütmek için saklanabilir.'],
                ['Güvenlik ve denetim','Oturum, yetki, moderasyon, hata ve audit kayıtları güvenlik, kötüye kullanım önleme ve hata araştırması için işlenebilir.'],
                ['Entegrasyonlar','CAPTCHA, OAuth, e-posta veya diğer isteğe bağlı entegrasyonlar etkinse yalnız ilgili işlev için gereken veriler ilgili sağlayıcıya aktarılabilir.'],
                ['Kontroller','Hesap ve gizlilikle ilgili kullanıcı kontrolleri hesap alanında sunulur. Saklama ve yasal yükümlülükler site işletmecisinin yapılandırmasına göre değişebilir.'],
            ],$basePath);
    }

    public static function cookies(BasePath $basePath): string
    {
        return self::document('Çerez kullanımı','ÇEREZLER',
            'Forwext temel oturum ve güvenlik akışlarında tarayıcı çerezlerinden yararlanır.',
            [
                ['Oturum','Giriş durumunu güvenli biçimde sürdürebilmek için oturum çerezi kullanılabilir.'],
                ['CSRF güvenliği','Form ve hesap işlemlerini siteler arası istek sahteciliğine karşı korumak için güvenlik belirteçleri kullanılabilir.'],
                ['Beni hatırla ve tercihler','Kullanıcı açıkça etkinleştirdiğinde kalıcı oturum veya arayüz tercihleri için ek tarayıcı verileri kullanılabilir.'],
                ['Üçüncü taraflar','Etkinleştirilen CAPTCHA, medya veya benzeri sağlayıcılar kendi teknik depolamalarını kullanabilir; kapsam etkin entegrasyona bağlıdır.'],
            ],$basePath);
    }

    /** @param list<array{tag:string,label:string,example:string,description:string}> $items */
    public static function bbCodes(array $items, BasePath $basePath): string
    {
        $rows='';
        foreach($items as $item){
            $rows.='<article class="surface-panel reference-row"><div><strong>'.self::e($item['label']).'</strong>'
                .'<span>'.self::e($item['description']).'</span></div><code>'.self::e($item['example']).'</code></article>';
        }
        return self::referencePage('BB kodları','BB KODLARI','Editörde desteklenen biçimlendirme söz dizimi.',$rows,$basePath);
    }

    /** @param array<string,array{emoji:string,label:string}> $items */
    public static function smilies(array $items, BasePath $basePath): string
    {
        $rows='';
        foreach($items as $key=>$item){
            $rows.='<article class="surface-panel emoji-reference"><span class="emoji-reference-glyph" aria-hidden="true">'
                .$item['emoji'].'</span><div><strong>'.self::e($item['label']).'</strong><code>:'.self::e($key).':</code></div></article>';
        }
        return self::referencePage('Emoji ve ifadeler','EMOJİ','Forwext emoji kataloğunda bulunan anahtarlar.','<div class="emoji-reference-grid">'.$rows.'</div>',$basePath);
    }

    /** @param list<TrophyDefinition> $items */
    public static function trophies(array $items, BasePath $basePath): string
    {
        $rows='';
        foreach($items as $item){
            $rule=$item->ruleType->value . ($item->threshold === null ? '' : ' · eşik ' . $item->threshold);
            $rows.='<article class="surface-panel trophy-reference"><div><span class="moderation-row-type">'
                .self::e($item->kind->value).'</span><h2>'.self::e($item->name).'</h2><p>'
                .self::e($item->description).'</p></div><span class="muted">'.self::e($rule).'</span></article>';
        }
        if($rows==='')$rows='<div class="surface-empty"><strong>Etkin kupa veya rozet yok.</strong><span>Yayınlanan tanımlar burada görünür.</span></div>';
        return self::referencePage('Kupalar ve rozetler','KUPALAR & ROZETLER','Etkin başarımlar doğrudan gerçek trophy kayıtlarından listelenir.',$rows,$basePath);
    }

    public static function feeds(BasePath $basePath): string
    {
        $rss=self::e($basePath->prepend('/feed.rss'));
        $atom=self::e($basePath->prepend('/feed.atom'));
        $rows='<a class="surface-panel public-reference-card" href="'.$rss.'"><strong>RSS 2.0</strong><span>/feed.rss</span></a>'
            .'<a class="surface-panel public-reference-card" href="'.$atom.'"><strong>Atom</strong><span>/feed.atom</span></a>';
        return self::referencePage('RSS / Atom','YAYIN AKIŞLARI','Herkese açık Forwext keşif akışlarını standart okuyucularla takip et.','<div class="public-reference-grid">'.$rows.'</div>',$basePath);
    }

    /** @param list<array{0:string,1:string}> $sections */
    private static function document(string $title,string $eyebrow,string $intro,array $sections,BasePath $basePath):string
    {
        $rows='';
        foreach($sections as [$heading,$text]){
            $rows.='<section class="surface-panel public-document-section"><h2>'.self::e($heading).'</h2><p>'.$text.'</p></section>';
        }
        return self::referencePage($title,$eyebrow,$intro,$rows,$basePath);
    }

    private static function referencePage(string $title,string $eyebrow,string $intro,string $content,BasePath $basePath):string
    {
        $body='<section class="public-reference discovery-page"><header class="surface-head public-reference-head"><div>'
            .'<span class="forum-eyebrow">'.self::e($eyebrow).'</span><h1>'.self::e($title).'</h1><p>'.self::e($intro).'</p></div>'
            .'<a class="fx-btn" href="'.self::e($basePath->prepend('/help')).'">Yardım merkezine dön</a></header>'
            .'<div class="public-reference-content">'.$content.'</div></section>';
        return ProfileHtml::page($title,$body,$basePath);
    }

    private static function link(BasePath $basePath,string $path,string $label):string
    {
        return '<a href="'.self::e($basePath->prepend($path)).'">'.self::e($label).'</a>.';
    }

    private static function e(string $value):string
    {
        return ProfileHtml::escape($value);
    }
}
