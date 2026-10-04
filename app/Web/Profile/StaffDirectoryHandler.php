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
            $page = self::page($request->query());
            $offset = ($page - 1) * self::PAGE_SIZE;
            $members = $this->directory->staffPublic(self::PAGE_SIZE, $offset);
            $total = $this->directory->countStaffPublic();
        } catch (ProfileException) {
            return Response::text('Bad Request', 400)->withHeader('X-Robots-Tag', 'noindex,nofollow');
        }

        $cards = '';
        foreach ($members as $member) {
            $username = $member['username'];
            $path = ProfileHtml::memberPath($this->basePath, $username);
            $safePath = ProfileHtml::escape($path);
            $avatar = $member['has_avatar']
                ? '<img class="avatar" src="' . $safePath . '/avatar" alt="">'
                : '<span class="avatar" aria-hidden="true">' . ProfileHtml::initial($username) . '</span>';

            $cards .= '<a class="member-directory-card is-staff" href="' . $safePath . '">' . $avatar
                . '<span class="member-directory-copy"><strong>' . ProfileHtml::escape($username) . '</strong>'
                . '<small><span class="member-staff-badge">Yetkili</span>'
                . '<span>Katılım · ' . ProfileHtml::escape(substr($member['created_at'], 0, 10)) . '</span></small>'
                . '</span></a>';
        }

        $cards = $cards === ''
            ? '<div class="surface-empty">Herkese açık profili bulunan yetkili üye bulunmuyor.</div>'
            : '<div class="member-directory-grid">' . $cards . '</div>';

        $body = '<section class="member-directory-page discovery-page"><header class="surface-head member-directory-head">'
            . '<div><span class="forum-eyebrow">TOPLULUK</span><h1>Yetkililer</h1>'
            . '<p>Herkese açık profili bulunan yetkili üyeler · ' . number_format($total, 0, ',', '.') . ' kişi</p></div>'
            . '<div class="member-directory-head-actions">'
            . '<a class="fx-btn" href="' . ProfileHtml::escape($this->basePath->prepend('/members')) . '">Tüm üyeler</a>'
            . '<a class="fx-btn" href="' . ProfileHtml::escape($this->basePath->prepend('/members/online')) . '">Çevrimiçi</a>'
            . '</div></header>'
            . '<section class="surface-panel member-directory-results">' . $cards
            . self::pagination($this->basePath, $page, $total) . '</section></section>';

        return Response::html(ProfileHtml::page(
            'Yetkililer',
            $body,
            $this->basePath,
            breadcrumbs: new BreadcrumbTrail([
                new BreadcrumbItem('Ana Sayfa', '/'),
                new BreadcrumbItem('Üyeler', '/members'),
                new BreadcrumbItem('Yetkililer'),
            ]),
        ));
    }

    /** @param array<string,mixed> $query */
    private static function page(array $query): int
    {
        $value = $query['page'] ?? null;
        if ($value === null || $value === '') {
            return 1;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,3}$/D', $value) !== 1 || (int) $value > 1000) {
            throw new ProfileException('Staff directory page is invalid.');
        }

        return (int) $value;
    }

    private static function pagination(BasePath $basePath, int $page, int $total): string
    {
        $lastPage = max(1, (int) ceil($total / self::PAGE_SIZE));
        if ($lastPage <= 1) {
            return '';
        }

        $targets = [1 => true, $lastPage => true];
        for ($candidate = max(1, $page - 2); $candidate <= min($lastPage, $page + 2); $candidate++) {
            $targets[$candidate] = true;
        }
        $targets = array_keys($targets);
        sort($targets, SORT_NUMERIC);

        $links = '';
        $previous = null;
        foreach ($targets as $target) {
            if ($previous !== null && $target > $previous + 1) {
                $links .= '<span class="pagination-gap" aria-hidden="true">…</span>';
            }
            $href = $basePath->prepend('/members/staff') . ($target === 1 ? '' : '?page=' . $target);
            $links .= '<a' . ($target === $page ? ' aria-current="page"' : '') . ' href="'
                . ProfileHtml::escape($href) . '">' . number_format($target, 0, ',', '.') . '</a>';
            $previous = $target;
        }

        return '<nav class="surface-pagination member-pagination" aria-label="Yetkili üye sayfaları">'
            . $links . '</nav>';
    }
}
