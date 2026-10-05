<?php

declare(strict_types=1);

namespace Forwext\App\Web\PublicReference;

use Forwext\Core\Forum\Editor\BbCodeReferenceCatalog;
use Forwext\Core\Forum\Editor\EmojiCatalog;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Trophy\TrophyRepository;

final readonly class PublicReferenceHandler
{
    public function __construct(
        private TrophyRepository $trophies,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $path = parse_url($request->uri(), PHP_URL_PATH);
        $route = is_string($path) ? $this->basePath->strip($path) : null;

        $html = match ($route) {
            '/help' => PublicReferenceHtml::index($this->basePath),
            '/help/contact' => PublicReferenceHtml::contact($this->basePath),
            '/help/terms' => PublicReferenceHtml::terms($this->basePath),
            '/help/privacy' => PublicReferenceHtml::privacy($this->basePath),
            '/help/cookies' => PublicReferenceHtml::cookies($this->basePath),
            '/help/bb-codes' => PublicReferenceHtml::bbCodes(BbCodeReferenceCatalog::all(), $this->basePath),
            '/help/smilies' => PublicReferenceHtml::smilies(EmojiCatalog::all(), $this->basePath),
            '/help/trophies' => PublicReferenceHtml::trophies($this->trophies->definitions(true), $this->basePath),
            '/help/rss' => PublicReferenceHtml::feeds($this->basePath),
            default => PublicReferenceHtml::index($this->basePath),
        };

        return Response::html($html)->withHeader('Cache-Control', 'public, max-age=120');
    }
}
