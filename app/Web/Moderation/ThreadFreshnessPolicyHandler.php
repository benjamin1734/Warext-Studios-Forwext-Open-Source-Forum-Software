<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Audit\HttpAuditRequestId;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Freshness\ThreadFreshnessAccessDeniedException;
use Forwext\Core\Forum\Freshness\ThreadFreshnessException;
use Forwext\Core\Forum\Freshness\ThreadFreshnessPolicy;
use Forwext\Core\Forum\Freshness\ThreadFreshnessService;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeType;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class ThreadFreshnessPolicyHandler implements RequestHandlerInterface
{
    public function __construct(
        private ThreadFreshnessService $freshness,
        private ForumNodeRepository $nodes,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) return Response::text('Authentication required.',401)->withHeader('Cache-Control','no-store');
        try {
            $input = $request->method() === HttpMethod::Post ? $request->parsedBody() : $request->query();
            $forumRaw = $input['forum'] ?? null;
            if (!is_string($forumRaw) || $forumRaw === '') {
                return $this->index($actor);
            }
            $forumId = EntityId::fromString($forumRaw);
            $node = $this->nodes->find($forumId);
            if ($node === null || $node->type() !== ForumNodeType::Forum) {
                throw new ThreadFreshnessException('Forum is unavailable.');
            }
            $now = new DateTimeImmutable('now',new DateTimeZone('UTC'));
            if ($request->method() === HttpMethod::Post) {
                $policy = new ThreadFreshnessPolicy(
                    $forumId,
                    ($input['enabled'] ?? null) === '1',
                    $this->requiredInt($input,'stale_after_days'),
                    $this->optionalInt($input,'notify_after_days'),
                    $this->optionalInt($input,'auto_lock_after_days'),
                    $this->optionalInt($input,'auto_archive_after_days'),
                    $this->optionalInt($input,'auto_unfeature_after_days'),
                    $this->optionalInt($input,'moderator_review_after_days'),
                    $this->requiredInt($input,'renewal_cooldown_hours'),
                );
                $this->freshness->savePolicy($actor,$policy,$now,HttpAuditRequestId::fromRequest($request));
            }
            $policy = $this->freshness->policy($actor,$forumId)
                ?? new ThreadFreshnessPolicy($forumId,false,30,21,45,90,30,60,24);
            $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($token) || $token === '') return Response::text('Internal Server Error',500);
            $body = '<section class="card"><h1>Konu güncellik politikası</h1>'
                . '<h2>'.ProfileHtml::escape($node->title()).'</h2>'
                . '<form method="post" action="'.ProfileHtml::escape($this->basePath->prepend('/moderation/freshness/policy')).'" class="search-form">'
                . '<input type="hidden" name="_csrf" value="'.ProfileHtml::escape($token).'">'
                . '<input type="hidden" name="forum" value="'.ProfileHtml::escape($forumId->value()).'">'
                . '<label><span>Aktif</span><select name="enabled"><option value="0"'.(!$policy->enabled?' selected':'').'>Hayır</option><option value="1"'.($policy->enabled?' selected':'').'>Evet</option></select></label>'
                . $this->number('stale_after_days','Stale sonrası gün',$policy->staleAfterDays,true)
                . $this->number('notify_after_days','Yazar bildirimi günü',$policy->notifyAfterDays)
                . $this->number('auto_unfeature_after_days','Öne çıkarmayı kaldır',$policy->autoUnfeatureAfterDays)
                . $this->number('auto_lock_after_days','Otomatik kilitle',$policy->autoLockAfterDays)
                . $this->number('moderator_review_after_days','Moderatör inceleme',$policy->moderatorReviewAfterDays)
                . $this->number('auto_archive_after_days','Otomatik arşivle',$policy->autoArchiveAfterDays)
                . $this->number('renewal_cooldown_hours','Yenileme bekleme saati',$policy->renewalCooldownHours,true)
                . '<button type="submit">Politikayı kaydet</button></form></section>';
            return Response::html(ProfileHtml::page('Konu güncellik politikası',$body,$this->basePath,authenticated:true))
                ->withHeader('Cache-Control','private, no-store');
        } catch (ThreadFreshnessAccessDeniedException) {
            return Response::text('Permission denied.',403)->withHeader('Cache-Control','no-store');
        } catch (ThreadFreshnessException|InvalidArgumentException) {
            return Response::text('Freshness policy request is invalid.',400)->withHeader('Cache-Control','no-store');
        }
    }

    private function index(EntityId $actor): Response
    {
        $body = '<section class="card"><h1>Konu güncellik politikaları</h1><p>Politikasını düzenlemek istediğiniz forumu seçin.</p>';
        $found = false;
        foreach ($this->nodes->all() as $node) {
            if ($node->type() !== ForumNodeType::Forum) continue;
            try {
                $this->freshness->policy($actor,$node->id());
            } catch (ThreadFreshnessAccessDeniedException) {
                continue;
            }
            $found = true;
            $url = $this->basePath->prepend('/moderation/freshness/policy?forum='.$node->id()->value());
            $body .= '<p><a href="'.ProfileHtml::escape($url).'">'.ProfileHtml::escape($node->title()).'</a></p>';
        }
        if (!$found) throw new ThreadFreshnessAccessDeniedException('No manageable freshness policy.');
        $body .= '</section>';
        return Response::html(ProfileHtml::page('Konu güncellik politikaları',$body,$this->basePath,authenticated:true))
            ->withHeader('Cache-Control','private, no-store');
    }

    /** @param array<string,mixed> $input */
    private function requiredInt(array $input,string $key): int
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || !ctype_digit($value)) throw new InvalidArgumentException('Numeric field is invalid.');
        return (int) $value;
    }

    /** @param array<string,mixed> $input */
    private function optionalInt(array $input,string $key): ?int
    {
        $value = $input[$key] ?? null;
        if ($value === null || $value === '') return null;
        if (!is_string($value) || !ctype_digit($value)) throw new InvalidArgumentException('Optional numeric field is invalid.');
        return (int) $value;
    }

    private function number(string $name,string $label,?int $value,bool $required=false): string
    {
        return '<label><span>'.ProfileHtml::escape($label).'</span><input type="number" min="0" max="3650" name="'
            .ProfileHtml::escape($name).'" value="'.($value===null?'':(string)$value).'"'.($required?' required':'').'></label>';
    }
}
