<?php

declare(strict_types=1);

namespace Forwext\App\Web;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;

final readonly class HomeHandler implements RequestHandlerInterface
{
    public function __construct(
        private string $version,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $members = ProfileHtml::escape($this->basePath->prepend('/members'));
        $body = '<section class="card"><h1 style="margin-top:0">Forwext Forum Platform</h1>'
            . '<p>Kurulum sağlıklı. Üye ve profil yüzeyi aktif.</p>'
            . '<p><a href="' . $members . '">Üyeler dizinine git →</a></p>'
            . '<p class="muted">Kurulu sürüm: <code>' . ProfileHtml::escape($this->version) . '</code></p></section>';

        return Response::html(ProfileHtml::page('Ana Sayfa', $body, $this->basePath));
    }
}
