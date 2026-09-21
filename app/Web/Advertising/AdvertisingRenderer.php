<?php

declare(strict_types=1);

namespace Forwext\App\Web\Advertising;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Advertising\AdvertisingCampaign;
use Forwext\Core\Advertising\AdvertisingKind;
use Forwext\Core\Routing\BasePath;

final readonly class AdvertisingRenderer
{
    public function __construct(private BasePath $basePath){}

    public function render(AdvertisingCampaign $campaign):string
    {
        $label=match($campaign->kind){
            AdvertisingKind::Advertisement=>'Sponsorlu',
            AdvertisingKind::Notice=>'Bilgilendirme',
            AdvertisingKind::Announcement=>'Duyuru',
        };
        $class=match($campaign->kind){
            AdvertisingKind::Advertisement=>'fx-ad',
            AdvertisingKind::Notice=>'fx-notice',
            AdvertisingKind::Announcement=>'fx-announcement',
        };
        $headline='<strong>'.ProfileHtml::escape($campaign->headline).'</strong>';
        if($campaign->destinationUrl!==null){
            $headline='<a rel="nofollow sponsored noopener" href="'
                .ProfileHtml::escape($this->basePath->prepend('/ads/click/'.$campaign->campaignId->value()))
                .'">'.$headline.'</a>';
        }

        return '<aside class="fx-placement '.$class.'" data-placement="'
            .ProfileHtml::escape($campaign->placementKey).'" data-campaign="'
            .ProfileHtml::escape($campaign->key).'">'
            .'<span class="fx-placement-label">'.ProfileHtml::escape($label).'</span>'
            .$headline
            .($campaign->body===''?'':'<p>'.nl2br(ProfileHtml::escape($campaign->body),false).'</p>')
            .'</aside>';
    }

    public static function styles():string
    {
        return '<style>'
            .'.fx-placement{margin:14px 0;padding:14px 16px;border:1px solid var(--line);border-radius:12px;background:var(--panel2);overflow-wrap:anywhere}'
            .'.fx-placement-label{display:inline-block;margin-bottom:5px;font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);font-weight:850}'
            .'.fx-placement strong{display:block;font-size:15px}.fx-placement a{text-decoration:none}.fx-placement a:hover strong{color:var(--accent)}'
            .'.fx-placement p{margin:6px 0 0;color:var(--muted)}'
            .'.fx-ad{border-color:#ff7a1a55}.fx-notice{border-color:#3d78a855;background:#122131}.fx-announcement{border-color:#8b6bb355;background:#21182e}'
            .'</style>';
    }
}
