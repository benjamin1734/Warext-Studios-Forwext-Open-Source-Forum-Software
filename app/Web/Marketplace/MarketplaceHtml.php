<?php

declare(strict_types=1);

namespace Forwext\App\Web\Marketplace;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Marketplace\MarketplaceCategory;
use Forwext\Core\Marketplace\MarketplaceCustomFieldDefinition;
use Forwext\Core\Marketplace\MarketplaceCustomFieldType;
use Forwext\Core\Marketplace\MarketplaceListing;
use Forwext\Core\Marketplace\MarketplaceListingCard;
use Forwext\Core\Marketplace\MarketplaceListingPromotion;
use Forwext\Core\Marketplace\MarketplaceListingQuery;
use Forwext\Core\Marketplace\MarketplaceReview;
use Forwext\Core\Marketplace\MarketplaceReviewSummary;
use Forwext\Core\Routing\BasePath;

final class MarketplaceHtml
{
    /** @param list<MarketplaceListingCard> $cards @param list<MarketplaceCategory> $categories */
    public static function browse(
        array $cards,array $categories,MarketplaceListingQuery $query,string $view,int $page,int $total,
        BasePath $basePath,bool $authenticated
    ):string{
        $action=self::e($basePath->prepend('/marketplace'));
        $category='';
        foreach($categories as $c){
            $selected=$query->categoryId?->equals($c->categoryId)===true?' selected':'';
            $category.='<option value="'.self::e($c->slug).'"'.$selected.'>'.self::e($c->name).'</option>';
        }
        $body='<section class="card market-head"><div><h1>Marketplace</h1><p class="muted">Topluluk ilanlarını keşfedin, filtreleyin ve satıcı profillerini inceleyin.</p></div>'
            .($authenticated?'<a class="market-manage-link" href="'.self::e($basePath->prepend('/marketplace/manage')).'">İlanlarımı yönet</a>':'')
            .'<form method="get" action="'.$action.'" class="search-form">'
            .'<label class="search-wide"><span>Ara</span><input name="q" maxlength="200" value="'.self::e($query->text??'').'" placeholder="İlan başlığı veya açıklama"></label>'
            .'<label><span>Kategori</span><select name="category"><option value="">Tümü</option>'.$category.'</select></label>'
            .'<label><span>Etiket</span><input name="tag" maxlength="64" value="'.self::e($query->tag??'').'"></label>'
            .'<label><span>Para birimi</span><input name="currency" maxlength="3" value="'.self::e($query->currency??'').'" placeholder="TRY"></label>'
            .'<label><span>Min. fiyat</span><input name="min" value="'.self::e(self::decimal($query->minPriceMinor)).'" placeholder="0.00"></label>'
            .'<label><span>Maks. fiyat</span><input name="max" value="'.self::e(self::decimal($query->maxPriceMinor)).'" placeholder="5000.00"></label>'
            .'<label><span>Sıralama</span><select name="sort">'
            .self::option('featured','Öne çıkanlar',$query->sort->value).self::option('newest','En yeni',$query->sort->value)
            .self::option('price_asc','Fiyat artan',$query->sort->value).self::option('price_desc','Fiyat azalan',$query->sort->value)
            .self::option('rating','Puan',$query->sort->value).'</select></label>'
            .'<label><span>Görünüm</span><select name="view">'.self::option('grid','Grid',$view).self::option('list','Liste',$view).'</select></label>'
            .'<label><input type="checkbox" name="featured" value="1"'.($query->featuredOnly?' checked':'').'> Sadece öne çıkanlar</label>'
            .'<div class="search-actions"><button type="submit">Filtrele</button><a href="'.$action.'">Temizle</a></div></form></section>'
            .'<section class="section"><div class="market-result-head"><h2>İlanlar</h2><span class="muted">'.$total.' sonuç</span></div>'
            .self::cards($cards,$basePath,$view).self::pagination(
                $basePath,$query,self::selectedCategorySlug($categories,$query->categoryId),$view,$page,$total
            ).'</section>';
        return ProfileHtml::page('Marketplace',$body,$basePath,authenticated:$authenticated);
    }

    /**
     * @param list<MarketplaceCustomFieldDefinition> $fields
     * @param list<MarketplaceReview> $reviews
     * @param array<string,string> $reviewAuthors
     */
    public static function detail(
        MarketplaceListing $listing,MarketplaceCategory $category,string $sellerUsername,array $fields,
        ?MarketplaceListingPromotion $promotion,array $reviews,array $reviewAuthors,MarketplaceReviewSummary $summary,
        bool $canReview,?MarketplaceReview $ownReview,?string $csrf,bool $canManage,BasePath $basePath,bool $reviewed,bool $authenticated
    ):string{
        $now=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));
        $badges='';
        if($promotion?->pinnedAt($now))$badges.='<span class="market-badge">Sabit</span>';
        if($promotion?->featuredAt($now))$badges.='<span class="market-badge">Öne Çıkan</span>';
        if($listing->state->value==='sold')$badges.='<span class="market-badge">Satıldı</span>';
        $gallery='';
        foreach($listing->media as $media){
            $gallery.='<figure><img loading="lazy" src="'.self::e($basePath->prepend('/marketplace/media/'.$media->mediaId->value()))
                .'" alt="'.self::e($media->altText).'"></figure>';
        }
        if($gallery!=='')$gallery='<div class="market-media">'.$gallery.'</div>';
        $sellerProfile=ProfileHtml::memberPath($basePath,$sellerUsername);
        $sellerMarket=$basePath->prepend('/marketplace/sellers/'.rawurlencode($sellerUsername));
        $custom='';
        $byKey=[];foreach($fields as $f)$byKey[$f->key]=$f;
        foreach($listing->customValues as $key=>$value){
            $label=$byKey[$key]->label??$key;
            $custom.='<dt>'.self::e($label).'</dt><dd>'.self::e(self::value($value)).'</dd>';
        }
        $tags='';
        foreach($listing->tags as $tag)$tags.='<a href="'.self::e($basePath->prepend('/marketplace?tag='.rawurlencode($tag))).'">#'.self::e($tag).'</a>';
        $reviewHtml='';
        foreach($reviews as $review){
            $author=$reviewAuthors[$review->reviewerUserId->value()]??'Silinmiş kullanıcı';
            $reviewHtml.='<article class="market-review"><div><strong>'.self::e($author).'</strong><span class="market-stars">'
                .self::e(self::stars($review->rating)).'</span></div><p>'.nl2br(self::e($review->body),false).'</p>'
                .'<small class="muted">'.self::e($review->updatedAt->format('Y-m-d H:i')).' UTC</small></article>';
        }
        if($reviewHtml==='')$reviewHtml='<p class="muted">Henüz görünür değerlendirme yok.</p>';
        $reviewForm='';
        if($canReview&&$csrf!==null){
            $reviewForm='<form method="post" action="'.self::e($basePath->prepend('/marketplace/listings/'.$listing->listingId->value().'/review')).'" class="search-form">'
                .self::csrf($csrf).'<label><span>Puan</span><select name="rating">';
            for($i=5;$i>=1;--$i)$reviewForm.='<option value="'.$i.'"'.(($ownReview?->rating??5)===$i?' selected':'').'>'.$i.'/5</option>';
            $reviewForm.='</select></label><label class="search-wide"><span>Yorum</span><textarea name="body" maxlength="5000" rows="5" required>'
                .self::e($ownReview?->body??'').'</textarea></label><div class="search-actions"><button type="submit">'
                .($ownReview===null?'Değerlendir':'Değerlendirmeyi güncelle').'</button></div></form>';
        }

        $body='<article class="card market-detail"><div class="market-detail-top"><div>'.$badges.'<div class="search-hit-type">'.self::e($category->name).'</div>'
            .'<h1>'.self::e($listing->title).'</h1></div><strong class="market-price">'.self::e(self::money($listing->price->minorUnits,$listing->price->currency)).'</strong></div>'
            .($reviewed?'<div class="search-alert market-success">Değerlendirmeniz kaydedildi.</div>':'')
            .'<p class="muted">Satıcı: <a href="'.self::e($sellerProfile).'">'.self::e($sellerUsername).'</a> · '
            .'<a href="'.self::e($sellerMarket).'">Satıcının ilanları</a> · '
            .self::e(self::rating($summary->average(),$summary->count)).'</p>'
            .($tags===''?'':'<div class="market-tags">'.$tags.'</div>')
            .$gallery.'<section class="section"><h2>Açıklama</h2><div class="about">'.nl2br(self::e($listing->description),false).'</div></section>'
            .($custom===''?'':'<section class="section"><h2>Özellikler</h2><dl class="market-specs">'.$custom.'</dl></section>')
            .($canManage?'<p><a href="'.self::e($basePath->prepend('/marketplace/manage?listing='.$listing->listingId->value())).'">Bu ilanı yönet</a></p>':'')
            .'<section class="section"><h2>Değerlendirmeler</h2>'.$reviewHtml.$reviewForm.'</section></article>';
        return ProfileHtml::page($listing->title,$body,$basePath,authenticated:$authenticated);
    }

    /** @param list<MarketplaceListingCard> $cards */
    public static function seller(string $username,array $cards,int $page,int $total,BasePath $basePath,bool $authenticated):string
    {
        $count=0;$sum=0;foreach($cards as $c){$count+=$c->reviewCount;$sum+=$c->ratingTotal;}
        $rating=$count===0?null:$sum/$count;
        $body='<section class="card"><h1>'.self::e($username).' · Marketplace</h1><p class="muted">'
            .'<a href="'.self::e(ProfileHtml::memberPath($basePath,$username)).'">Forum profiline git</a> · '
            .$total.' herkese açık ilan · '.self::e(self::rating($rating,$count)).'</p></section>'
            .'<section class="section">'.self::cards($cards,$basePath,'grid')
            .self::sellerPagination($basePath,$username,$page,$total).'</section>';
        return ProfileHtml::page($username.' Marketplace',$body,$basePath,authenticated:$authenticated);
    }

    /**
     * @param list<MarketplaceListing> $listings
     * @param list<MarketplaceCategory> $categories
     * @param list<MarketplaceCustomFieldDefinition> $fields
     * @param list<MarketplaceReview> $reviews
     * @param array<string,string> $reviewAuthors
     */
    public static function manage(
        array $listings,array $categories,?MarketplaceListing $selected,?EntityId $newCategoryId,array $fields,
        ?MarketplaceListingPromotion $promotion,array $reviews,array $reviewAuthors,
        bool $canCreate,bool $canManageAll,bool $canFeature,bool $canModerateReviews,
        BasePath $basePath,string $csrf,bool $updated
    ):string{
        $action=self::e($basePath->prepend('/marketplace/manage'));
        $body='<section class="card"><h1>Marketplace İlan Yönetimi</h1><p class="muted">İlan oluşturun, düzenleyin ve yaşam döngüsünü yönetin.</p>'
            .($updated?'<div class="search-alert market-success">İşlem kaydedildi.</div>':'')
            .'<section class="section"><h2>İlanlar</h2>';
        if($listings===[])$body.='<p class="muted">Henüz yönetebileceğiniz ilan yok.</p>';
        foreach($listings as $listing){
            $body.='<article class="search-hit"><div class="search-hit-type">'.self::e($listing->state->value).'</div><h3><a href="'
                .self::e($basePath->prepend('/marketplace/manage?listing='.$listing->listingId->value())).'">'.self::e($listing->title).'</a></h3>'
                .'<p class="muted">'.self::e(self::money($listing->price->minorUnits,$listing->price->currency)).'</p></article>';
        }
        $body.='</section>';

        if($selected===null&&$newCategoryId===null&&$canCreate){
            $body.='<section class="section"><h2>Yeni ilan</h2><p class="muted">Önce kategori seçin.</p><div class="market-category-links">';
            foreach($categories as $category)$body.='<a href="'.self::e($basePath->prepend('/marketplace/manage?new_category='.$category->categoryId->value())).'">'.self::e($category->name).'</a>';
            $body.='</div></section>';
        }

        $formCategory=$selected?->categoryId??$newCategoryId;
        if($formCategory!==null&&($selected!==null||$canCreate)){
            $current=[];if($selected!==null)$current=$selected->customValues;
            $body.='<section class="section"><h2>'.($selected===null?'Yeni ilan':'İlanı düzenle').'</h2>'
                .'<form method="post" action="'.$action.'" class="search-form">'.self::csrf($csrf)
                .'<input type="hidden" name="action" value="listing_save"><input type="hidden" name="listing_id" value="'.self::e($selected?->listingId->value()??'').'">'
                .'<input type="hidden" name="category_id" value="'.self::e($formCategory->value()).'">'
                .'<label><span>Slug</span><input name="slug" maxlength="160" required value="'.self::e($selected?->slug??'').'"></label>'
                .'<label><span>Başlık</span><input name="title" maxlength="180" required value="'.self::e($selected?->title??'').'"></label>'
                .'<label><span>Fiyat</span><input name="price" required value="'.self::e($selected===null?'':self::decimal($selected->price->minorUnits)).'" placeholder="1250.00"></label>'
                .'<label><span>Para birimi</span><input name="currency" maxlength="3" required value="'.self::e($selected?->price->currency??'TRY').'"></label>'
                .'<label class="search-wide"><span>Etiketler (virgülle)</span><input name="tags" value="'.self::e($selected===null?'':implode(', ',$selected->tags)).'"></label>'
                .'<label class="search-wide"><span>Açıklama</span><textarea name="description" maxlength="120000" rows="10" required>'.self::e($selected?->description??'').'</textarea></label>';
            foreach($fields as $field)$body.=self::customField($field,$current[$field->key]??null);
            $body.='<div class="search-actions"><button type="submit">İlanı kaydet</button></div></form></section>';
        }

        if($selected!==null){
            $body.='<section class="section"><h2>İlan görselleri</h2><div class="market-media">';
            foreach($selected->media as $media){
                $body.='<figure><img src="'.self::e($basePath->prepend('/marketplace/media/'.$media->mediaId->value())).'" alt="'.self::e($media->altText).'">'
                    .'<form method="post" enctype="multipart/form-data" action="'.self::e($basePath->prepend('/marketplace/listings/'.$selected->listingId->value().'/media')).'">'
                    .self::csrf($csrf).'<input type="hidden" name="action" value="delete"><input type="hidden" name="media_id" value="'.$media->mediaId->value().'">'
                    .'<button type="submit">Görseli sil</button></form></figure>';
            }
            $body.='</div><form method="post" enctype="multipart/form-data" action="'.self::e($basePath->prepend('/marketplace/listings/'.$selected->listingId->value().'/media')).'" class="search-form">'
                .self::csrf($csrf).'<label><span>Görsel (JPEG/PNG/WebP)</span><input type="file" name="file" accept="image/jpeg,image/png,image/webp" required></label>'
                .'<label><span>Alt metin</span><input name="alt" maxlength="500"></label><div class="search-actions"><button type="submit">Görsel yükle</button></div></form></section>';
            $body.='<section class="section"><h2>Yaşam döngüsü</h2><div class="market-actions">'
                .self::stateActions($selected,$action,$csrf,$canManageAll).'</div></section>';
            if($canFeature&&$selected->state->publicVisible()){
                $body.='<section class="section"><h2>Öne çıkarma / sabitleme</h2><form method="post" action="'.$action.'" class="search-form">'
                    .self::csrf($csrf).'<input type="hidden" name="action" value="promotion_save"><input type="hidden" name="listing_id" value="'.$selected->listingId->value().'">'
                    .'<label><span>Öne çıkarma bitişi (UTC)</span><input type="datetime-local" name="featured_until" value="'.self::e($promotion?->featuredUntil?->format('Y-m-d\TH:i')??'').'"></label>'
                    .'<label><span>Sabitleme bitişi (UTC)</span><input type="datetime-local" name="pinned_until" value="'.self::e($promotion?->pinnedUntil?->format('Y-m-d\TH:i')??'').'"></label>'
                    .'<div class="search-actions"><button type="submit">Yerleşimi kaydet</button></div></form></section>';
            }
            if($canModerateReviews){
                $body.='<section class="section"><h2>Review moderasyonu</h2>';
                if($reviews===[])$body.='<p class="muted">Bu ilanda review yok.</p>';
                foreach($reviews as $review){
                    $author=$reviewAuthors[$review->reviewerUserId->value()]??$review->reviewerUserId->value();
                    $body.='<article class="market-review"><strong>'.self::e($author).' · '.$review->rating.'/5 · '.self::e($review->state->value).'</strong>'
                        .'<p>'.nl2br(self::e($review->body),false).'</p><form method="post" action="'.$action.'" class="market-actions">'
                        .self::csrf($csrf).'<input type="hidden" name="action" value="review_moderate"><input type="hidden" name="review_id" value="'.$review->reviewId->value().'">'
                        .'<input type="hidden" name="visible" value="'.($review->state->value==='visible'?'0':'1').'"><button type="submit">'
                        .($review->state->value==='visible'?'Gizle':'Görünür yap').'</button></form></article>';
                }
                $body.='</section>';
            }
        }

        $body.='</section>';
        return ProfileHtml::page('Marketplace Yönetimi',$body,$basePath,authenticated:true);
    }

    /** @param list<MarketplaceListingCard> $cards */
    public static function cards(array $cards,BasePath $basePath,string $view='grid'):string
    {
        if($cards===[])return '<div class="card empty">Bu filtrelerle ilan bulunamadı.</div>';
        $body='<div class="market-cards '.($view==='list'?'market-list':'market-grid').'">';
        foreach($cards as $card){
            $badges='';
            if($card->pinned)$badges.='<span class="market-badge">Sabit</span>';
            if($card->featured)$badges.='<span class="market-badge">Öne Çıkan</span>';
            if($card->state->value==='sold')$badges.='<span class="market-badge">Satıldı</span>';
            $cover=$card->coverMediaId===null?'':'<a class="market-cover" href="'.self::e($basePath->prepend('/marketplace/listings/'.$card->listingId->value()))
                .'"><img loading="lazy" src="'.self::e($basePath->prepend('/marketplace/media/'.$card->coverMediaId->value())).'" alt=""></a>';
            $body.='<article class="market-card">'.$cover.'<div>'.$badges.'<div class="search-hit-type">'.self::e($card->categoryName).'</div>'
                .'<h3><a href="'.self::e($basePath->prepend('/marketplace/listings/'.$card->listingId->value())).'">'.self::e($card->title).'</a></h3>'
                .'<p class="market-price">'.self::e(self::money($card->price->minorUnits,$card->price->currency)).'</p>'
                .'<p class="muted">Satıcı: <a href="'.self::e($basePath->prepend('/marketplace/sellers/'.rawurlencode($card->sellerUsername))).'">'
                .self::e($card->sellerUsername).'</a> · '.self::e(self::rating($card->averageRating(),$card->reviewCount)).'</p></div></article>';
        }
        return $body.'</div>';
    }

    private static function stateActions(MarketplaceListing $listing,string $action,string $csrf,bool $staff):string
    {
        $buttons=[];
        if(in_array($listing->state->value,['draft','paused'],true))$buttons[]=['submit','İncelemeye gönder'];
        if($staff&&$listing->state->value==='pending')$buttons[]=['approve','Onayla'];
        if($listing->state->value==='active')$buttons[]=['pause','Duraklat'];
        if(in_array($listing->state->value,['active','paused'],true))$buttons[]=['sold','Satıldı işaretle'];
        if(in_array($listing->state->value,['draft','pending','active','paused'],true))$buttons[]=['close','Kapat'];
        if($staff&&in_array($listing->state->value,['sold','closed'],true))$buttons[]=['archive','Arşivle'];
        $html='';
        foreach($buttons as [$name,$label]){
            $html.='<form method="post" action="'.$action.'">'.self::csrf($csrf)
                .'<input type="hidden" name="action" value="'.$name.'"><input type="hidden" name="listing_id" value="'.$listing->listingId->value().'">'
                .'<button type="submit">'.$label.'</button></form>';
        }
        return $html===''?'<span class="muted">Bu durumda kullanılabilir geçiş yok.</span>':$html;
    }

    /** @param array<string,mixed> $query */
    private static function pagination(BasePath $basePath,MarketplaceListingQuery $query,?string $categorySlug,string $view,int $page,int $total):string
    {
        $last=max(1,(int)ceil($total/24));if($last<=1)return '';
        $links='';
        foreach(array_unique(array_filter([$page-1,$page,$page+1],static fn(int $p):bool=>$p>=1&&$p<=$last)) as $p){
            $params=['page'=>$p,'view'=>$view,'sort'=>$query->sort->value];
            if($query->text!==null)$params['q']=$query->text;
            if($categorySlug!==null)$params['category']=$categorySlug;
            if($query->currency!==null)$params['currency']=$query->currency;
            if($query->minPriceMinor!==null)$params['min']=self::decimal($query->minPriceMinor);
            if($query->maxPriceMinor!==null)$params['max']=self::decimal($query->maxPriceMinor);
            if($query->tag!==null)$params['tag']=$query->tag;
            if($query->featuredOnly)$params['featured']='1';
            $href=$basePath->prepend('/marketplace').'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
            $links.='<a'.($p===$page?' aria-current="page"':'').' href="'.self::e($href).'">'.$p.'</a>';
        }
        return '<nav class="pagination" aria-label="Marketplace sayfaları">'.$links.'</nav>';
    }

    private static function sellerPagination(BasePath $basePath,string $username,int $page,int $total):string
    {
        $last=max(1,(int)ceil($total/24));
        if($last<=1)return '';
        $links='';
        foreach(array_unique(array_filter([$page-1,$page,$page+1],static fn(int $p):bool=>$p>=1&&$p<=$last)) as $target){
            $href=$basePath->prepend('/marketplace/sellers/'.rawurlencode($username)).'?page='.$target;
            $links.='<a'.($target===$page?' aria-current="page"':'').' href="'.self::e($href).'">'.$target.'</a>';
        }
        return '<nav class="pagination" aria-label="Satıcı ilan sayfaları">'.$links.'</nav>';
    }

    /** @param list<MarketplaceCategory> $categories */
    private static function selectedCategorySlug(array $categories,?EntityId $categoryId):?string
    {
        if($categoryId===null)return null;
        foreach($categories as $category){
            if($category->categoryId->equals($categoryId))return $category->slug;
        }
        return null;
    }

    /** @param array<string,string|int|bool> $current */
    private static function customField(MarketplaceCustomFieldDefinition $field,mixed $value):string
    {
        $name='custom_'.$field->key;$label=self::e($field->label).($field->required?' *':'');
        return match($field->type){
            MarketplaceCustomFieldType::Text=>'<label class="search-wide"><span>'.$label.'</span><input name="'.$name.'" maxlength="5000" value="'.self::e(is_string($value)?$value:'').'"></label>',
            MarketplaceCustomFieldType::Integer=>'<label><span>'.$label.'</span><input type="number" name="'.$name.'" value="'.self::e(is_int($value)?(string)$value:'').'"></label>',
            MarketplaceCustomFieldType::Boolean=>'<label><input type="checkbox" name="'.$name.'" value="1"'.($value===true?' checked':'').'> '.$label.'</label>',
            MarketplaceCustomFieldType::Select=>'<label><span>'.$label.'</span><select name="'.$name.'"><option value="">—</option>'.implode('',array_map(
                static fn(string $o):string=>'<option value="'.self::e($o).'"'.($value===$o?' selected':'').'>'.self::e($o).'</option>',$field->options
            )).'</select></label>',
        };
    }

    private static function money(int $minor,string $currency):string{return number_format($minor/100,2,',','.').' '.$currency;}
    private static function decimal(?int $minor):string{return $minor===null?'':number_format($minor/100,2,'.','');}
    private static function rating(?float $average,int $count):string{return $average===null?'Puan yok':number_format($average,1,',','.').'/5 ('.$count.')';}
    private static function stars(int $rating):string{return str_repeat('★',$rating).str_repeat('☆',5-$rating);}
    private static function value(string|int|bool $value):string{return is_bool($value)?($value?'Evet':'Hayır'):(string)$value;}
    private static function csrf(string $token):string{return '<input type="hidden" name="_csrf" value="'.self::e($token).'">';}
    private static function option(string $value,string $label,string $selected):string{return '<option value="'.self::e($value).'"'.($value===$selected?' selected':'').'>'.self::e($label).'</option>';}
    private static function e(string $value):string{return ProfileHtml::escape($value);}
}
