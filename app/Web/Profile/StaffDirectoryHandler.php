<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Profile\ProfileDirectoryReader;
use Forwext\Core\Profile\ProfileException;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbItem;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbTrail;

final readonly class StaffDirectoryHandler implements RequestHandlerInterface
{
    private const PAGE_SIZE = 24;

    public function __construct(
        private ProfileDirectoryReader $directory,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $query = self::scalar($request->query(), 'q', 64) ?? '';
            $page = self::page($request->query());
            $offset = ($page - 1) * self::PAGE_SIZE;
            $members = $this->directory->searchPublicStaff($query, self::PAGE_SIZE, $offset);
            $total = $this->directory->countPublicStaff($query);
        } catch (ProfileException) {
            return Response::text('Bad Request', 400)->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        $cards = '';
        foreach ($members as $member) {
            $username = $member['username'];
            $path = ProfileHtml::memberPath($this->basePath, $username);
            $safePath = ProfileHtml::escape($path);
            $safeUsername = ProfileHtml::escape($username);
            $joined = ProfileHtml::escape(substr($member['created_at'], 0, 10));
            $avatar = $member['has_avatar']
                ? '<img class="avatar" src="' . $safePath . '/avatar" alt="">'
                : '<span class="avatar" aria-hidden="true">' . ProfileHtml::initial($username) . '</span>';

            $cards .= '<a class="member-directory-card member-staff-card" href="' . $safePath . '">'
                . $avatar
                . '<span class="member-directory-copy"><strong>' . $safeUsername . '</strong>'
                . '<small>Yetkili ekip · Katılım ' . $joined . '</small></span></a>';
        }

        $cards = $cards === ''
            ? '<div class="surface-empty">Bu filtrelerle görüntülenebilir yetkili bulunmuyor.</div>'
            : '<div class="member-directory-grid">' . $cards . '</div>';

        $action = ProfileHtml::escape($this->basePath->prepend('/members/staff'));
        $body = '<section class="member-directory-page member-staff-page discovery-page">'
            . '<header class="surface-head member-directory-head"><div><span class="forum-eyebrow">TOPLULUK</span>'
            . '<h1>Yetkili Ekip</h1><p>Herkese açık profili bulunan aktif yetkililer · '
            . number_format($total, 0, ',', '.') . ' kişi</p></div>'
            . '<a class="fx-btn" href="' . ProfileHtml::escape($this->basePath->prepend('/members')) . '">Tüm üyeler</a>'
            . '</header>'
            . '<section class="surface-panel member-directory-filter"><form class="member-directory-form" method="get" action="'
            . $action . '"><label><span>Kullanıcı ara</span><input name="q" maxlength="64" value="'
            . ProfileHtml::escape($query) . '" placeholder="Kullanıcı adı"></label>'
            . '<button type="submit">Filtrele</button></form></section>'
            . '<section class="surface-panel member-directory-results">' . $cards
            . self::pagination($this->basePath, $query, $page, $total) . '</section></section>';

        $breadcrumbs = new BreadcrumbTrail([
            new BreadcrumbItem('Ana Sayfa', '/'),
            new BreadcrumbItem('Üyeler', '/members'),
            new BreadcrumbItem('Yetkili Ekip'),
        ]);

        return Response::html(ProfileHtml::page(
            'Yetkili Ekip',
            $body,
            $this->basePath,
            breadcrumbs: $breadcrumbs,
        ));
    }

    /** @param array<string, mixed> $query */
    private static function scalar(array $query, string $key, int $maxBytes): ?string
    {
        $value = $query[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || strlen($value) > $maxBytes || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new ProfileException('Staff directory parameter is invalid.');
        }
        return trim($value);
    }

    /** @param array<string, mixed> $query */
    private static function page(array $query): int
    {
        $value = self::scalar($query, 'page', 4);
        if ($value === null || $value === '') {
            return 1;
        }
        if (preg_match('/^[1-9][0-9]{0,3}$/D', $value) !== 1 || (int) $value > 1000) {
            throw new ProfileException('Staff directory page is invalid.');
        }
        return (int) $value;
    }

    private static function pagination(BasePath $basePath, string $query, int $page, int $total): string
    {
        $lastPage = max(1, (int) ceil($total / self::PAGE_SIZE));
        if ($lastPage <= 1) {
            return '';
        }

        $links = '';
        foreach (array_unique(array_filter(
            [$page - 1, $page, $page + 1],
            static fn (int $candidate): bool => $candidate >= 1 && $candidate <= $lastPage,
        )) as $target) {
            $params = ['page' => $target];
            if ($query !== '') {
                $params['q'] = $query;
            }
            $href = $basePath->prepend('/members/staff') . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $links .= '<a' . ($target === $page ? ' aria-current="page"' : '') . ' href="'
                . ProfileHtml::escape($href) . '">' . $target . '</a>';
        }

        return '<nav class="surface-pagination member-pagination" aria-label="Yetkili sayfaları">' . $links . '</nav>';
    }
}
