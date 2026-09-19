<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

use DateTimeImmutable;
use DateTimeZone;
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
                    $result = $this->freshness->maintain($now,100);
                    $message = 'Bakım turu: '.$result->scanned.' tarandı, '.$result->notified.' bildirim, '
                        .$result->locked.' kilit, '.$result->archived.' arşiv, '.$result->unfeatured.' öne çıkarma kaldırma, '
                        .$result->reviewsCreated.' inceleme.';
                } elseif ($mode === 'resolve') {
                    $threadRaw = $body['thread_id'] ?? null;
                    $resolution = $body['resolution'] ?? null;
                    if (!is_string($threadRaw) || !is_string($resolution)) throw new InvalidArgumentException('Review form invalid.');
                    $this->freshness->resolveReview($actor,EntityId::fromString($threadRaw),$resolution,$now);
                    $message = 'İnceleme sonuçlandırıldı.';
                } else {
                    throw new InvalidArgumentException('Review mode invalid.');
                }
                $reviews = $this->freshness->pendingReviews($actor,$now,100);
            }

            $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($token) || $token === '') return Response::text('Internal Server Error',500);
            $action = ProfileHtml::escape($this->basePath->prepend('/moderation/freshness'));
            $body = '<section class="card"><h1>Konu güncellik incelemeleri</h1>'
                . ($message===null?'':'<div class="notice">'.ProfileHtml::escape($message).'</div>')
                . '<form method="post" action="'.$action.'"><input type="hidden" name="_csrf" value="'.ProfileHtml::escape($token).'">'
                . '<button type="submit" name="mode" value="maintain">100 konuluk bakım turu çalıştır</button></form></section>'
                . '<section class="card" style="margin-top:18px"><h2>Bekleyen incelemeler</h2>';
            if ($reviews === []) {
                $body .= '<p class="muted">Bekleyen inceleme yok.</p>';
            }
            foreach ($reviews as $review) {
                $body .= '<article class="search-hit"><h3>'.ProfileHtml::escape($review->title).'</h3>'
                    . '<p>'.$review->ageDays.' gündür güncellenmedi.</p>'
                    . '<form method="post" action="'.$action.'">'
                    . '<input type="hidden" name="_csrf" value="'.ProfileHtml::escape($token).'">'
                    . '<input type="hidden" name="mode" value="resolve">'
                    . '<input type="hidden" name="thread_id" value="'.ProfileHtml::escape($review->threadId->value()).'">'
                    . '<button name="resolution" value="keep">Olduğu gibi bırak</button> '
                    . '<button name="resolution" value="renew">Yenile/aç</button> '
                    . '<button name="resolution" value="archive">Arşivle</button></form></article>';
            }
            $body .= '</section>';
            return Response::html(ProfileHtml::page('Konu güncellik incelemeleri',$body,$this->basePath,authenticated:true))
                ->withHeader('Cache-Control','private, no-store');
        } catch (ThreadFreshnessAccessDeniedException) {
            return Response::text('Permission denied.',403)->withHeader('Cache-Control','no-store');
        } catch (ThreadFreshnessException|InvalidArgumentException) {
            return Response::text('Freshness review request is invalid.',400)->withHeader('Cache-Control','no-store');
        }
    }
}
