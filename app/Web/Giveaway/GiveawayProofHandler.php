<?php

declare(strict_types=1);

namespace Forwext\App\Web\Giveaway;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Giveaway\GiveawayDrawException;
use Forwext\Core\Giveaway\GiveawayDrawService;
use Forwext\Core\Giveaway\GiveawayException;
use Forwext\Core\Giveaway\GiveawayService;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;

final readonly class GiveawayProofHandler implements RequestHandlerInterface
{
    public function __construct(
        private GiveawayService $giveaways,
        private GiveawayDrawService $draws,
        private UserRepository $users,
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
        $giveawayId = $this->giveawayId($request);
        if ($giveawayId === null) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }

        try {
            $this->giveaways->syncDue(new DateTimeImmutable('now', new DateTimeZone('UTC')), 100);
            $giveaway = $this->giveaways->find($actor, $giveawayId);
            $proofs = $this->draws->proof($actor, $giveawayId);
            $names = [];
            foreach ($proofs as $proof) {
                $id = $proof->draw->winnerUserId;
                if (isset($names[$id->value()])) {
                    continue;
                }
                $user = $this->users->find($id);
                if ($user !== null) {
                    $names[$id->value()] = $user->username()->display();
                }
            }

            return Response::html(GiveawayHtml::proof(
                $giveaway,
                $proofs,
                $names,
                $this->basePath,
            ))->withHeader('Cache-Control', 'private, no-store');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (GiveawayException|GiveawayDrawException) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function giveawayId(Request $request): ?EntityId
    {
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $value = is_array($parameters) ? ($parameters['giveawayId'] ?? null) : null;
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            return null;
        }
        return EntityId::fromString($value);
    }
}
