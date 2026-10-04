<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Profile\ProfileDirectoryReader;
use Forwext\Core\Profile\ProfileException;
use Forwext\Core\Routing\BasePath;

final readonly class MemberDirectoryHandler implements RequestHandlerInterface
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
            $sort = self::scalar($request->query(), 'sort', 16) ?? 'newest';
            if (!in_array($sort, ['newest', 'username'], true)) {
                throw new ProfileException('Member directory sort is invalid.');
            }
            $page = self::page($request->query());
            $offset = ($page - 1) * self::PAGE_SIZE;
            $members = $this->directory->searchPublic($query, $sort, self::PAGE_SIZE, $offset);
            $total = $this->directory->countPublic($query);
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
            $cards .= '<a class="member-directory-card" href="' . $safePath . '" aria-label="'
                . $safeUsername . ' profilini aç">' . $avatar
                . '<span class="member-directory-copy"><strong>' . $safeUsername
                . '</strong><small>Katılım · ' . $joined . '</small></span></a>';
        }
        $cards = $cards === ''
            ? '<div class="surface-empty">Bu filtrelerle gösterilebilecek herkese açık üye bulunmuyor.</div>'
            : '<div class="member-directory-grid">' . $cards . '</div>';

        $action = ProfileHtml::escape($this->basePath->prepend('/members'));
        $body = '<section class="member-directory-page discovery-page"><header class="surface-head member-directory-head">'
            . '<div><span class="forum-eyebrow">TOPLULUK</span><h1>Üyeler</h1>'
            . '<p>Herkese açık aktif profiller · ' . number_format($total, 0, ',', '.') . ' üye</p></div>'
            . '<div class="member-directory-head-actions"><a class="fx-btn" href="'
            . ProfileHtml::escape($this->basePath->prepend('/members/staff')) . '">Yetkili ekip</a>'
            . '<a class="fx-btn" href="' . ProfileHtml::escape($this->basePath->prepend('/members/online'))
            . '">Çevrimiçi üyeler</a></div></header>'
            . '<section class="surface-panel member-directory-filter"><form class="member-directory-form" method="get" action="' . $action
            . '" aria-label="Üye dizini filtreleri">'
            . '<label><span>Kullanıcı ara</span><input name="q" maxlength="64" value="'
            . ProfileHtml::escape($query) . '" placeholder="Kullanıcı adı"></label>'
            . '<label><span>Sıralama</span><select name="sort">'
            . '<option value="newest"' . ($sort === 'newest' ? ' selected' : '') . '>En yeni</option>'
            . '<option value="username"' . ($sort === 'username' ? ' selected' : '') . '>Kullanıcı adı</option>'
            . '</select></label><button type="submit">Filtrele</button></form></section>'
            . '<section class="surface-panel member-directory-results">'
            . '<div class="member-directory-summary" role="status"><span>Sonuçlar</span><strong>'
            . number_format($total, 0, ',', '.') . '</strong></div>' . $cards
            . self::pagination($this->basePath, $query, $sort, $page, $total) . '</section></section>';

        return Response::html(ProfileHtml::page('Üyeler', $body, $this->basePath));
    }

    /** @param array<string, mixed> $query */
    private static function scalar(array $query, string $key, int $maxBytes): ?string
    {
        $value = $query[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || strlen($value) > $maxBytes || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new ProfileException('Member directory parameter is invalid.');
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
            throw new ProfileException('Member directory page is invalid.');
        }
        return (int) $value;
    }

    private static function pagination(
        BasePath $basePath,
        string $query,
        string $sort,
        int $page,
        int $total,
    ): string {
        $lastPage = max(1, (int) ceil($total / self::PAGE_SIZE));
        if ($lastPage <= 1) {
            return '';
        }

        $links = '';
        if ($page > 1) {
            $links .= self::pageLink($basePath, $query, $sort, $page - 1, 'Önceki');
        }

        $targets = array_values(array_unique(array_filter(
            [1, $page - 1, $page, $page + 1, $lastPage],
            static fn (int $candidate): bool => $candidate >= 1 && $candidate <= $lastPage,
        )));
        sort($targets);

        $previous = null;
        foreach ($targets as $target) {
            if ($previous !== null && $target - $previous > 1) {
                $links .= '<span class="member-pagination-gap" aria-hidden="true">…</span>';
            }
            $links .= self::pageLink($basePath, $query, $sort, $target, (string) $target, $target === $page);
            $previous = $target;
        }

        if ($page < $lastPage) {
            $links .= self::pageLink($basePath, $query, $sort, $page + 1, 'Sonraki');
        }

        return '<nav class="surface-pagination member-pagination" aria-label="Üye sayfaları">' . $links . '</nav>';
    }

    private static function pageLink(
        BasePath $basePath,
        string $query,
        string $sort,
        int $target,
        string $label,
        bool $current = false,
    ): string {
        $params = ['page' => $target, 'sort' => $sort];
        if ($query !== '') {
            $params['q'] = $query;
        }
        $href = $basePath->prepend('/members') . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return '<a' . ($current ? ' aria-current="page"' : '') . ' href="'
            . ProfileHtml::escape($href) . '">' . ProfileHtml::escape($label) . '</a>';
    }
}
