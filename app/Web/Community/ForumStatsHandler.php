<?php

declare(strict_types=1);

namespace Forwext\App\Web\Community;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Forum\Stats\ForumStatsService;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Presence\PresenceService;
use Forwext\Core\Routing\BasePath;

final readonly class ForumStatsHandler implements RequestHandlerInterface
{
    public function __construct(
        private ForumStatsService $stats,
        private PresenceService $presence,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            $body = '<section class="card"><h1 style="margin-top:0">Forum İstatistikleri</h1>'
                . '<p>Yetkiye göre forum istatistiklerini görmek için oturum açmalısınız.</p></section>';
            return Response::html(ProfileHtml::page('Forum İstatistikleri', $body, $this->basePath), 401)
                ->withHeader('Cache-Control', 'private, no-store');
        }

        $this->presence->heartbeat($actor);
        $stats = $this->stats->forUser($actor);
        $online = count($this->presence->online(true, 100));
        $body = '<section class="card"><h1 style="margin-top:0">Forum İstatistikleri</h1>'
            . '<p class="muted">Forum/thread/post sayıları yalnız görüntüleme yetkinizin bulunduğu listelenebilir forumları kapsar.</p></section>'
            . '<div class="stats-grid" style="margin-top:16px">'
            . self::stat('Forumlar', $stats->forums)
            . self::stat('Konular', $stats->threads)
            . self::stat('Mesajlar', $stats->posts)
            . self::stat('Aktif üyeler', $stats->activeMembers)
            . self::stat('Çevrimiçi', $online)
            . '</div>';

        return Response::html(ProfileHtml::page(
            'Forum İstatistikleri',
            $body,
            $this->basePath,
            authenticated: true,
        ))->withHeader('Cache-Control', 'private, no-store');
    }

    private static function stat(string $label, int $value): string
    {
        return '<div class="card stat"><span class="muted">' . ProfileHtml::escape($label)
            . '</span><strong>' . number_format($value, 0, ',', '.') . '</strong></div>';
    }
}
