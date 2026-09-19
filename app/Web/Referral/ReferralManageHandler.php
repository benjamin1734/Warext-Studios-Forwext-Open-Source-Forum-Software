<?php

declare(strict_types=1);

namespace Forwext\App\Web\Referral;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Audit\HttpAuditRequestId;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Referral\ReferralCampaign;
use Forwext\Core\Referral\ReferralException;
use Forwext\Core\Referral\ReferralService;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class ReferralManageHandler implements RequestHandlerInterface
{
    public function __construct(
        private ReferralService $referrals,
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
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        try {
            if ($request->method() === HttpMethod::Post) {
                $this->mutate($actor, $request, $now);
                return Response::redirect(
                    $this->basePath->prepend('/referrals/manage?updated=1'),
                    303,
                )->withHeader('Cache-Control', 'no-store');
            }

            $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($token) || $token === '') {
                return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
            }

            return Response::html(ReferralHtml::manage(
                $this->referrals->campaigns($actor),
                $this->referrals->reviewQueue($actor),
                $this->referrals->siteAnalytics($actor),
                $this->basePath,
                $token,
                ($request->query()['updated'] ?? null) === '1',
            ))->withHeader('Cache-Control', 'private, no-store');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (ReferralException|InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function mutate(EntityId $actor, Request $request, DateTimeImmutable $now): void
    {
        $body = $request->parsedBody();
        $action = $body['action'] ?? null;
        if (!is_string($action)) {
            throw new InvalidArgumentException('Referral management action is missing.');
        }

        if ($action === 'qualify_due') {
            $this->referrals->qualifyDue($actor, 100, $now);
            return;
        }

        if ($action === 'review') {
            $id = self::id($body['attribution_id'] ?? null);
            $decision = $body['decision'] ?? null;
            if (!is_string($decision) || !in_array($decision, ['approve','reject'], true)) {
                throw new InvalidArgumentException('Referral review decision is invalid.');
            }
            $this->referrals->review(
                $actor,
                $id,
                $decision === 'approve',
                $now,
                HttpAuditRequestId::fromRequest($request),
            );
            return;
        }

        if ($action !== 'campaign_save') {
            throw new InvalidArgumentException('Referral management action is invalid.');
        }

        $rawId = self::optional($body, 'campaign_id', 32);
        $campaignId = $rawId === null ? ReferralCampaign::generateId() : self::id($rawId);
        $startsAt = self::date(self::required($body, 'starts_at', 32));
        $endsRaw = self::optional($body, 'ends_at', 32);
        $endsAt = $endsRaw === null ? null : self::date($endsRaw);

        $campaign = new ReferralCampaign(
            $campaignId,
            strtolower(self::required($body, 'key', 64)),
            self::required($body, 'name', 120),
            self::checked($body, 'active'),
            $startsAt,
            $endsAt,
            self::integer($body, 'qualification_delay', 0, 31_536_000, 86_400),
            self::integer($body, 'attribution_window', 3_600, 31_536_000, 2_592_000),
            self::integer($body, 'network_limit', 1, 100, 1),
            self::integer($body, 'device_limit', 1, 100, 1),
            self::nullableInteger($body, 'max_qualified', 1, 1_000_000),
            strtolower(self::required($body, 'reward_key', 64)),
            self::integer($body, 'reward_units', 1, 1_000_000_000, 1),
        );
        $this->referrals->saveCampaign(
            $actor,
            $campaign,
            $now,
            HttpAuditRequestId::fromRequest($request),
        );
    }

    private static function id(mixed $value): EntityId
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Referral id is invalid.');
        }
        return EntityId::fromString($value);
    }

    /** @param array<string,mixed> $body */
    private static function required(array $body, string $key, int $max): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Referral field is missing.');
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $max) {
            throw new InvalidArgumentException('Referral field is invalid.');
        }
        return $value;
    }

    /** @param array<string,mixed> $body */
    private static function optional(array $body, string $key, int $max): ?string
    {
        $value = $body[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > $max) {
            throw new InvalidArgumentException('Referral optional field is invalid.');
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $body */
    private static function integer(array $body, string $key, int $min, int $max, int $default): int
    {
        if (!array_key_exists($key, $body) || $body[$key] === '') {
            return $default;
        }
        $value = filter_var($body[$key], FILTER_VALIDATE_INT);
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException('Referral integer field is invalid.');
        }
        return $value;
    }

    /** @param array<string,mixed> $body */
    private static function nullableInteger(array $body, string $key, int $min, int $max): ?int
    {
        if (!array_key_exists($key, $body) || $body[$key] === '') {
            return null;
        }
        return self::integer($body, $key, $min, $max, $min);
    }

    /** @param array<string,mixed> $body */
    private static function checked(array $body, string $key): bool
    {
        return ($body[$key] ?? null) === '1' || ($body[$key] ?? null) === 1 || ($body[$key] ?? null) === true;
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d\TH:i') !== $value) {
            throw new InvalidArgumentException('Referral date field is invalid.');
        }
        return $date;
    }
}
