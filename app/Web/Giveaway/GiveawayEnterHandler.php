<?php

declare(strict_types=1);

namespace Forwext\App\Web\Giveaway;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Giveaway\GiveawayException;
use Forwext\Core\Giveaway\GiveawayFingerprint;
use Forwext\Core\Giveaway\GiveawayParticipationException;
use Forwext\Core\Giveaway\GiveawayParticipationService;
use Forwext\Core\Giveaway\GiveawayService;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class GiveawayEnterHandler implements RequestHandlerInterface
{
    public function __construct(
        private GiveawayService $giveaways,
        private GiveawayParticipationService $participation,
        private GiveawayFingerprint $fingerprint,
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
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $this->giveaways->syncDue($now, 100);
            [$network, $device] = GiveawayRequestFingerprint::fromRequest($request, $this->fingerprint);
            $this->participation->enter($actor, $giveawayId, $network, $device, $now);
            return Response::text('', 303)
                ->withHeader('Location', $this->basePath->prepend(
                    '/giveaways/' . rawurlencode($giveawayId->value()) . '?entered=1',
                ))
                ->withHeader('Cache-Control', 'no-store');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (GiveawayParticipationException $exception) {
            $reason = $exception->reasonCodes[0] ?? 'ineligible';
            if (preg_match('/^[a-z_]{1,40}$/D', $reason) !== 1) {
                $reason = 'ineligible';
            }
            return Response::text('', 303)
                ->withHeader('Location', $this->basePath->prepend(
                    '/giveaways/' . rawurlencode($giveawayId->value())
                    . '?entry_error=' . rawurlencode($reason),
                ))
                ->withHeader('Cache-Control', 'no-store');
        } catch (GiveawayException) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
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
