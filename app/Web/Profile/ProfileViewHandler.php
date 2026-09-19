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
        $body = '<article class="profile">' . $banner . '<div class="profilebody"><div class="profilehead">'
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
                && in_array($tab->key, ['overview', 'portfolio', 'about'], true)
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
