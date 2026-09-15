<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Profile\ProfileDirectoryReader;
use Forwext\Core\Routing\BasePath;

final readonly class MemberDirectoryHandler implements RequestHandlerInterface
{
    public function __construct(
        private ProfileDirectoryReader $directory,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $members = $this->directory->latestPublic(36);
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

            $cards .= '<a class="card member" href="' . $safePath . '">' . $avatar
                . '<span><strong>' . $safeUsername . '</strong><br><small class="muted">Katılım: '
                . $joined . '</small></span></a>';
        }

        if ($cards === '') {
            $cards = '<div class="card empty">Gösterilebilecek herkese açık üye profili bulunmuyor.</div>';
        } else {
            $cards = '<div class="grid">' . $cards . '</div>';
        }

        $body = '<div style="margin-bottom:20px"><h1 style="margin:0 0 5px">Üyeler</h1>'
            . '<div class="muted">Herkese açık ve aktif üye profilleri.</div></div>' . $cards;

        return Response::html(ProfileHtml::page('Üyeler', $body, $this->basePath));
    }
}
