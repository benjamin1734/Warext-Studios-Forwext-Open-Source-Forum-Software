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
use Forwext\Core\Forum\Freshness\ThreadFreshnessService;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class ThreadFreshnessReviewHandler implements RequestHandlerInterface
{
    public function __construct(
        private ThreadFreshnessService $freshness,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) return Response::text('Authentication required.',401)->withHeader('Cache-Control','no-store');
        $now = new DateTimeImmutable('now',new DateTimeZone('UTC'));
        try {
            $reviews = $this->freshness->pendingReviews($actor,$now,100);
            $message = null;
            if ($request->method() === HttpMethod::Post) {
                $body = $request->parsedBody();
                $mode = $body['mode'] ?? null;
                if ($mode === 'maintain') {
                    $result = $this->freshness->maintainForActor($actor,$now,100,HttpAuditRequestId::fromRequest($request));
                    $message = 'Bakım turu: '.$result->scanned.' tarandı, '.$result->notified.' bildirim, '
                        .$result->locked.' kilit, '.$result->archived.' arşiv, '.$result->unfeatured.' öne çıkarma kaldırma, '
                        .$result->reviewsCreated.' inceleme.';
                } elseif ($mode === 'resolve') {
                    $threadRaw = $body['thread_id'] ?? null;
                    $resolution = $body['resolution'] ?? null;
                    if (!is_string($threadRaw) || !is_string($resolution)) throw new InvalidArgumentException('Review form invalid.');
                    $this->freshness->resolveReview($actor,EntityId::fromString($threadRaw),$resolution,$now,HttpAuditRequestId::fromRequest($request));
                    $message = 'İnceleme sonuçlandırıldı.';
                } else {
                    throw new InvalidArgumentException('Review mode invalid.');
                }
                $reviews = $this->freshness->pendingReviews($actor,$now,100);
            }

            $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($token) || $token === '') return Response::text('Internal Server Error',500);
            $action = ProfileHtml::escape($this->basePath->prepend('/moderation/freshness'));
            $body = '<section class="moderation-subpage discovery-page"><header class="surface-head moderation-subpage-head"><div>'
                . '<span class="forum-eyebrow">KONU GÜNCELLİĞİ</span><h1>Güncellik incelemeleri</h1>'
                . '<p>Stale eşiklerine ulaşan konuları incele ve bakım turunu çalıştır.</p></div>'
                . '<a class="fx-btn" href="'.ProfileHtml::escape($this->basePath->prepend('/moderation/freshness/policy')).'">Politikalar</a></header>'
                . ($message===null?'':'<div class="notification-settings-notice" role="status">'.ProfileHtml::escape($message).'</div>')
                . '<section class="surface-panel freshness-maintenance"><div><h2>Bakım turu</h2>'
                . '<p>En fazla 100 konuyu tarar; bildirim, kilit, arşiv ve inceleme kurallarını uygular.</p></div>'
                . '<form method="post" action="'.$action.'"><input type="hidden" name="_csrf" value="'.ProfileHtml::escape($token).'">'
                . '<button class="fx-btn fx-btn--primary" type="submit" name="mode" value="maintain">100 konuluk bakım turu</button></form></section>'
                . '<section class="surface-panel moderation-report-panel"><header><h2>Bekleyen incelemeler</h2><span>'
                . count($reviews) . '</span></header><div class="moderation-list">';
            if ($reviews === []) {
                $body .= '<div class="surface-empty"><strong>Bekleyen inceleme yok.</strong><span>Kuyruk şu anda temiz.</span></div>';
            }
            foreach ($reviews as $review) {
                $body .= '<article class="freshness-review-row"><div><span class="moderation-row-type">STALE</span><h3>'
                    . ProfileHtml::escape($review->title) . '</h3><p>' . $review->ageDays . ' gündür güncellenmedi.</p></div>'
                    . '<form method="post" action="'.$action.'" class="freshness-review-actions">'
                    . '<input type="hidden" name="_csrf" value="'.ProfileHtml::escape($token).'">'
                    . '<input type="hidden" name="mode" value="resolve">'
                    . '<input type="hidden" name="thread_id" value="'.ProfileHtml::escape($review->threadId->value()).'">'
                    . '<button class="fx-btn" name="resolution" value="keep">Bırak</button>'
                    . '<button class="fx-btn" name="resolution" value="renew">Yenile/aç</button>'
                    . '<button class="fx-btn" name="resolution" value="archive">Arşivle</button></form></article>';
            }
            $body .= '</div></section></section>';
            return Response::html(ProfileHtml::page('Konu güncellik incelemeleri',$body,$this->basePath,authenticated:true))
                ->withHeader('Cache-Control','private, no-store');
        } catch (ThreadFreshnessAccessDeniedException) {
            return Response::text('Permission denied.',403)->withHeader('Cache-Control','no-store');
        } catch (ThreadFreshnessException|InvalidArgumentException) {
            return Response::text('Freshness review request is invalid.',400)->withHeader('Cache-Control','no-store');
        }
    }
}
