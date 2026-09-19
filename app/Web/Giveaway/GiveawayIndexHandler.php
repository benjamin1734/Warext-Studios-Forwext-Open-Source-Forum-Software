<?php

declare(strict_types=1);

namespace Forwext\App\Web\Giveaway;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Giveaway\GiveawayService;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;

final readonly class GiveawayIndexHandler implements RequestHandlerInterface
{
    public function __construct(
        private GiveawayService $giveaways,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }

        try {
            $this->giveaways->syncDue(new DateTimeImmutable('now', new DateTimeZone('UTC')), 100);
            return Response::html(GiveawayHtml::index(
                $this->giveaways->visible($actor, 100),
                $this->basePath,
                $this->giveaways->canCreate($actor) || $this->giveaways->canManage($actor),
            ))->withHeader('Cache-Control', 'private, no-store');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        }
    }
}
