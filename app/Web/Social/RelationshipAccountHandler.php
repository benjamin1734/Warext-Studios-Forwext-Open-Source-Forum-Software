<?php

declare(strict_types=1);

namespace Forwext\App\Web\Social;

use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Social\Interaction\DatabaseSocialRelationshipReader;
use Forwext\Core\Social\Interaction\SocialInteractionException;

final readonly class RelationshipAccountHandler implements RequestHandlerInterface
{
    public function __construct(
        private DatabaseSocialRelationshipReader $relationships,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
        private DateTimeZone $timezone,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }

        try {
            return Response::html(RelationshipAccountHtml::page(
                $this->relationships->following($actor),
                $this->relationships->followers($actor),
                $this->relationships->ignored($actor),
                $this->basePath,
                $this->timezone,
            ))->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Robots-Tag', 'noindex,nofollow');
        } catch (SocialInteractionException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }
}
