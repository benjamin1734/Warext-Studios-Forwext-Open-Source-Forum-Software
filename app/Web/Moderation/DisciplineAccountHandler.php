<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Moderation\Discipline\DisciplineRepository;
use Forwext\Core\Routing\BasePath;

final readonly class DisciplineAccountHandler implements RequestHandlerInterface
{
    public function __construct(
        private DisciplineRepository $discipline,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $userId = $this->viewers->resolve($request);
        if ($userId === null) {
            return Response::text('Unauthorized', 401)->withHeader('Cache-Control', 'no-store');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return Response::html(DisciplineAccountHtml::page(
            $this->discipline->forUser($userId, 100),
            $this->discipline->activePoints($userId, $now),
            $now,
            $this->basePath,
        ))->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
