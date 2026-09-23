<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Marketplace\MarketplaceListingQuery;
use Forwext\Core\Marketplace\MarketplaceListingSort;
use Forwext\Core\Marketplace\MarketplaceService;
use Forwext\Core\Portfolio\PortfolioService;
use Forwext\Core\Profile\Music\ProfileMusicService;
use Forwext\Core\Profile\Music\ProfileMusicSourceType;
use Forwext\Core\Profile\ProfileAccessPolicy;
use Forwext\Core\Profile\ProfileException;
use Forwext\Core\Profile\ProfileService;
use Forwext\Core\Profile\ProfileTab;
use Forwext\Core\Profile\SocialLink;
use Forwext\Core\Profile\UserProfile;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use Forwext\Core\Trophy\TrophyHistoryAction;
use Forwext\Core\Trophy\TrophyService;
use InvalidArgumentException;

final readonly class ProfileViewHandler implements RequestHandlerInterface
{
    /** @var array<string, string> */
    private const SOCIAL_LABELS = [
        'discord' => 'Discord',
        'facebook' => 'Facebook',
        'github' => 'GitHub',
        'instagram' => 'Instagram',
        'linkedin' => 'LinkedIn',
        'mastodon' => 'Mastodon',
        'twitch' => 'Twitch',
        'x' => 'X',
        'youtube' => 'YouTube',
    ];

    public function __construct(
        private UserRepository $users,
        private ProfileService $profiles,
        private ProfileAccessPolicy $accessPolicy,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
        private ?ProfileMusicService $music = null,
        private ?PortfolioService $portfolio = null,
        private ?TrophyService $trophies = null,
        private ?MarketplaceService $marketplace = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        $username = $this->routeUsername($request);
        if ($username === null) {
            return Response::text('Not Found', 404);
        }

        $user = $this->users->findByUsername($username);
        if ($user === null || $user->status() !== UserStatus::Active) {
            return Response::text('Not Found', 404);
        }

        $viewerId = $this->viewers->resolve($request);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $profile = $this->profiles->visibleProfile($user->id(), $viewerId, $now);
        if ($profile === null) {
            return Response::text('Not Found', 404);
        }

        $displayName = $user->username()->display();
        $memberPath = ProfileHtml::memberPath($this->basePath, $displayName);
        $canSeeMedia = $this->accessPolicy->canViewSection(
            $profile,
            $profile->mediaVisibility,
            $viewerId,
        );
        $avatar = $canSeeMedia && $profile->avatarPath !== null
            ? '<img class="avatar" src="' . ProfileHtml::escape($memberPath . '/avatar') . '" alt="">'
            : '<span class="avatar" aria-hidden="true">' . ProfileHtml::initial($displayName) . '</span>';
        $banner = $canSeeMedia && $profile->bannerPath !== null
            ? '<img class="banner" src="' . ProfileHtml::escape($memberPath . '/banner') . '" alt="">'
            : '<div class="banner" aria-hidden="true"></div>';

        $visibleTabs = $this->visibleSupportedTabs($profile, $viewerId);
        $tabNav = '';
        foreach ($visibleTabs as $tab) {
            $label = match ($tab->key) {
                'overview' => 'Genel Bakış',
                'portfolio' => 'Portfolyo',
                'achievements' => 'Başarımlar',
                'marketplace' => 'Marketplace',
                default => 'Hakkımda',
            };
            $tabNav .= '<a href="#' . ProfileHtml::escape($tab->key) . '">' . $label . '</a>';
        }
        $tabNav = $tabNav === ''
            ? ''
            : '<nav class="tabs" aria-label="Profil sekmeleri">' . $tabNav . '</nav>';

        $sections = '';
        foreach ($visibleTabs as $tab) {
            if ($tab->key === 'overview') {
                $sections .= $this->overviewSection(
                    $profile,
                    $viewerId,
                    $user->createdAt()->format('Y-m-d'),
                );
            } elseif ($tab->key === 'portfolio') {
                $sections .= $this->portfolioSection($profile, $viewerId);
            } elseif ($tab->key === 'achievements') {
                $sections .= $this->trophySection($profile->userId, $viewerId);
            } elseif ($tab->key === 'marketplace') {
                $sections .= $this->marketplaceSection($profile->userId, $viewerId);
            } elseif ($tab->key === 'about') {
                $sections .= $this->aboutSection($profile, $viewerId);
            }
        }
        if ($sections === '') {
            $sections = '<div class="empty">Bu profil için görüntülenebilir bölüm bulunmuyor.</div>';
        }

        $safeName = ProfileHtml::escape($displayName);
        $profileSettings = $this->accessPolicy->canEdit($user->id(), $viewerId)
            ? '<a class="profile-settings-link" href="'
                . ProfileHtml::escape($this->basePath->prepend('/account/profile-url'))
                . '">Özel profil URL’si</a>'
            : '';
        $music = $this->musicPlayer($user->id(), $viewerId, $now, $memberPath);
        $body = '<article class="profile" data-forwext-background-scope="profile" data-forwext-background-id="'
            . ProfileHtml::escape($profile->userId->value()) . '">' . $banner
            . '<div class="profilebody"><div class="profilehead">'
            . $avatar . '<div class="identity"><h1>' . $safeName
            . '</h1><div class="muted">Forwext üyesi</div>' . $profileSettings . '</div></div>'
            . $music . $tabNav . $sections . '</div></article>';

        return Response::html(ProfileHtml::page($displayName, $body, $this->basePath));
    }

    private function routeUsername(Request $request): ?Username
    {
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $value = is_array($parameters) ? ($parameters['username'] ?? null) : null;
        if (!is_string($value)) {
            return null;
        }

        try {
            return Username::fromString($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @return list<ProfileTab> */
    private function visibleSupportedTabs(UserProfile $profile, ?EntityId $viewerId): array
    {
        $tabs = array_values(array_filter(
            $profile->tabs,
            fn (ProfileTab $tab): bool => $tab->enabled
                && in_array($tab->key, ['overview', 'portfolio', 'achievements', 'marketplace', 'about'], true)
                && $this->accessPolicy->canViewSection($profile, $tab->visibility, $viewerId),
        ));
        usort(
            $tabs,
            static fn (ProfileTab $left, ProfileTab $right): int => $left->sortOrder <=> $right->sortOrder,
        );
        return $tabs;
    }

    private function overviewSection(UserProfile $profile, ?EntityId $viewerId, string $joined): string
    {
        $content = '<div class="muted">Katılım tarihi: ' . ProfileHtml::escape($joined) . '</div>';
        if ($this->accessPolicy->canViewSection($profile, $profile->socialVisibility, $viewerId)) {
            $links = $this->socialLinks($profile, $viewerId);
            if ($links !== '') {
                $content .= '<div class="section"><h2>Sosyal bağlantılar</h2><div class="social">'
                    . $links . '</div></div>';
            }
        }

        return '<section class="section" id="overview"><h2>Genel Bakış</h2>' . $content . '</section>';
    }

    private function portfolioSection(UserProfile $profile, ?EntityId $viewerId): string
    {
        if ($this->portfolio === null) {
            return '';
        }

        try {
            $projects = $this->portfolio->projects($viewerId, $profile->userId, false, 12);
        } catch (PermissionDeniedException|InvalidArgumentException) {
            return '';
        }

        $content = '';
        foreach ($projects as $project) {
            $href = $this->basePath->prepend('/portfolio/' . rawurlencode($project->projectId->value()));
            $content .= '<article class="search-hit"><div class="search-hit-type">'
                . ProfileHtml::escape($project->categoryKey)
                . ($project->featured ? ' · Öne Çıkan' : '')
                . '</div><h3><a href="' . ProfileHtml::escape($href) . '">'
                . ProfileHtml::escape($project->title) . '</a></h3>'
                . ($project->summary === '' ? '' : '<p>' . ProfileHtml::escape($project->summary) . '</p>')
                . '</article>';
        }

        if ($content === '') {
            $content = '<p class="muted">Henüz yayımlanmış portfolyo projesi yok.</p>';
        }

        return '<section class="section" id="portfolio"><h2>Portfolyo</h2>' . $content . '</section>';
    }

    private function trophySection(EntityId $userId, ?EntityId $viewerId): string
    {
        if($this->trophies===null||$viewerId===null)return '';

        try{
            $awards=$this->trophies->profileAwards($viewerId,$userId,50);
            $history=$this->trophies->profileHistory($viewerId,$userId,20);
        }catch(PermissionDeniedException|InvalidArgumentException){
            return '';
        }

        $cards='';
        foreach($awards as $item){
            $definition=$item['definition'];
            $grant=$item['grant'];
            $icon=$definition->iconPath===null?'':'<img class="trophy-icon" src="'
                .ProfileHtml::escape($this->basePath->prepend($definition->iconPath)).'" alt="">';
            $banner=$definition->bannerPath===null?'':'<img class="trophy-banner" src="'
                .ProfileHtml::escape($this->basePath->prepend($definition->bannerPath)).'" alt="">';
            $cards.='<article class="trophy-card">'.$banner.'<div class="trophy-card-body">'.$icon
                .'<div><div class="search-hit-type">'.ProfileHtml::escape($definition->kind->value)
                .' · Öncelik '.$definition->priority.'</div><h3>'.ProfileHtml::escape($definition->name).'</h3>'
                .($definition->description===''?'':'<p>'.ProfileHtml::escape($definition->description).'</p>')
                .'<p class="muted">Kazanım: '.ProfileHtml::escape($grant->awardedAt->format('Y-m-d H:i')).' UTC</p>'
                .'</div></div></article>';
        }
        if($cards==='')$cards='<p class="muted">Henüz görüntülenebilir kupa, rozet veya başarım yok.</p>';

        $timeline='';
        foreach($history as $item){
            $definition=$item['definition'];
            $event=$item['history'];
            $action=$event->action===TrophyHistoryAction::Awarded?'Kazanıldı':'Geri alındı';
            $timeline.='<li><strong>'.ProfileHtml::escape($definition->name).'</strong> · '
                .$action.' · '.ProfileHtml::escape($event->occurredAt->format('Y-m-d H:i')).' UTC</li>';
        }
        if($timeline!=='')$timeline='<div class="section"><h3>Başarım geçmişi</h3><ul class="trophy-history">'.$timeline.'</ul></div>';

        return '<section class="section" id="achievements"><h2>Kupa, Rozet ve Başarımlar</h2>'
            .'<div class="trophy-grid">'.$cards.'</div>'.$timeline.'</section>';
    }

    private function marketplaceSection(EntityId $userId,?EntityId $viewerId):string
    {
        if($this->marketplace===null)return '';
        try{
            $cards=$this->marketplace->browse(
                $viewerId,
                new MarketplaceListingQuery(sellerUserId:$userId,sort:MarketplaceListingSort::Featured),
                12,0
            );
        }catch(PermissionDeniedException|InvalidArgumentException){
            return '';
        }
        $content='';
        foreach($cards as $card){
            $content.='<article class="search-hit"><div class="search-hit-type">'
                .ProfileHtml::escape($card->categoryName).($card->featured?' · Öne Çıkan':'')
                .'</div><h3><a href="'.ProfileHtml::escape($this->basePath->prepend('/marketplace/listings/'.$card->listingId->value())).'">'
                .ProfileHtml::escape($card->title).'</a></h3><p class="muted">'
                .ProfileHtml::escape(number_format($card->price->minorUnits/100,2,',','.').' '.$card->price->currency)
                .'</p></article>';
        }
        if($content==='')$content='<p class="muted">Henüz herkese açık Marketplace ilanı yok.</p>';
        return '<section class="section" id="marketplace"><h2>Marketplace</h2>'.$content.'</section>';
    }

    private function aboutSection(UserProfile $profile, ?EntityId $viewerId): string
    {
        if (!$this->accessPolicy->canViewSection($profile, $profile->aboutVisibility, $viewerId)) {
            return '';
        }

        $about = $profile->about === ''
            ? '<span class="muted">Henüz bir hakkımda metni eklenmemiş.</span>'
            : ProfileHtml::escape($profile->about);

        return '<section class="section" id="about"><h2>Hakkımda</h2><div class="about">'
            . $about . '</div></section>';
    }

    private function socialLinks(UserProfile $profile, ?EntityId $viewerId): string
    {
        $links = '';
        foreach ($profile->socialLinks as $link) {
            if (!$link instanceof SocialLink
                || !$this->accessPolicy->canViewSection($profile, $link->visibility, $viewerId)
            ) {
                continue;
            }
            $label = self::SOCIAL_LABELS[$link->key] ?? $link->key;
            $links .= '<a href="' . ProfileHtml::escape($link->url)
                . '" target="_blank" rel="nofollow noopener noreferrer">'
                . ProfileHtml::escape($label) . '</a>';
        }
        return $links;
    }

    private function musicPlayer(
        EntityId $userId,
        ?EntityId $viewerId,
        DateTimeImmutable $now,
        string $memberPath,
    ): string {
        if ($this->music === null) {
            return '';
        }

        $settings = $this->music->visiblePlayback($userId, $viewerId, $now);
        if ($settings === null) {
            return '';
        }

        if ($settings->sourceType === ProfileMusicSourceType::Upload) {
            $source = $memberPath . '/music';
        } else {
            try {
                $source = $this->music->externalUrl($userId, $viewerId, $now);
            } catch (ProfileException) {
                return '';
            }
        }
        if ($source === null) {
            return '';
        }

        $title = $settings->title === '' ? 'Profil müziği' : $settings->title;
        $autoplay = $settings->autoplay && $this->music->autoplayAllowed($userId);
        $loop = $settings->loop ? ' loop' : '';

        return '<section class="profilemusic" data-profile-music data-volume="' . $settings->volume
            . '" data-muted="' . ($settings->muted ? '1' : '0')
            . '" data-autoplay="' . ($autoplay ? '1' : '0') . '">'
            . '<div class="profilemusic-title">' . ProfileHtml::escape($title) . '</div>'
            . '<audio controls preload="metadata"' . $loop . ' src="' . ProfileHtml::escape($source)
            . '" aria-label="Profil müziği"></audio></section>';
    }
}
