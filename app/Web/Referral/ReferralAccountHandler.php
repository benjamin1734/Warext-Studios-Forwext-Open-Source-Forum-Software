<?php

declare(strict_types=1);

namespace Forwext\App\Web\Referral;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Referral\ReferralException;
use Forwext\Core\Referral\ReferralService;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class ReferralAccountHandler implements RequestHandlerInterface
{
    public function __construct(
        private ReferralService $referrals,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
        private string $canonicalUrl,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            if ($request->method() === HttpMethod::Post) {
                $body = $request->parsedBody();
                if (($body['action'] ?? null) !== 'create_link') {
                    throw new InvalidArgumentException('Referral account action is invalid.');
                }
                $campaignId = self::id($body['campaign_id'] ?? null);
                $this->referrals->ensureLink($actor, $campaignId, $now);
                return Response::redirect(
                    $this->basePath->prepend('/account/referrals?updated=1'),
                    303,
                )->withHeader('Cache-Control', 'no-store');
            }

            $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($token) || $token === '') {
                return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
            }

            return Response::html(ReferralHtml::account(
                $this->referrals->activeCampaigns($actor, $now),
                $this->referrals->ownLinks($actor),
                $this->referrals->ownRewards($actor),
                $this->referrals->ownAnalytics($actor),
                $this->basePath,
                $this->canonicalUrl,
                $token,
                ($request->query()['updated'] ?? null) === '1',
                $this->referrals->canManage($actor),
            ))->withHeader('Cache-Control', 'private, no-store');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (ReferralException|InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    private static function id(mixed $value): EntityId
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Referral campaign id is invalid.');
        }
        return EntityId::fromString($value);
    }
}
