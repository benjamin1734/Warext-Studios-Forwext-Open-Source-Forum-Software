<?php

declare(strict_types=1);

namespace Forwext\App\Web\Forum;

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

final readonly class ThreadFreshnessHandler implements RequestHandlerInterface
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
        if ($actor === null) {
            return Response::text('Authentication required.',401)->withHeader('Cache-Control','no-store');
        }
        try {
            $raw = $request->attribute('threadId');
            if (!is_string($raw)) throw new InvalidArgumentException('Thread id is unavailable.');
            $threadId = EntityId::fromString($raw);
            $now = new DateTimeImmutable('now',new DateTimeZone('UTC'));
            $message = null;
            if ($request->method() === HttpMethod::Post) {
                $snapshot = $this->freshness->renew($actor,$threadId,$now,HttpAuditRequestId::fromRequest($request));
                $message = 'Konu güncelliği yenilendi.';
            } else {
                $snapshot = $this->freshness->snapshot($actor,$threadId,$now);
            }
            $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($token) || $token === '') {
                return Response::text('Internal Server Error',500)->withHeader('Cache-Control','no-store');
            }
            $badge = $snapshot->badge();
            $body = '<section class="card"><h1>Konu güncelliği</h1>'
                . ($message === null ? '' : '<div class="notice">'.ProfileHtml::escape($message).'</div>')
                . '<h2>'.ProfileHtml::escape($snapshot->title).'</h2>'
                . ($badge === null ? '<p><strong>Durum:</strong> Güncel</p>' : '<p><strong>Rozet:</strong> '.ProfileHtml::escape($badge).'</p>')
                . '<p><strong>Son etkinlik:</strong> '.ProfileHtml::escape($snapshot->lastActivityAt->format('Y-m-d H:i')).' UTC</p>'
                . '<p><strong>Yaş:</strong> '.$snapshot->ageDays.' gün</p>'
                . '<p><strong>Yenileme sayısı:</strong> '.$snapshot->renewCount.'</p>'
                . '<form method="post" action="'.ProfileHtml::escape($this->basePath->prepend('/threads/'.$threadId->value().'/freshness')).'">'
                . '<input type="hidden" name="_csrf" value="'.ProfileHtml::escape($token).'">'
                . '<button type="submit">Konuyu yenile</button></form></section>';
            return Response::html(ProfileHtml::page('Konu güncelliği',$body,$this->basePath,authenticated:true))
                ->withHeader('Cache-Control','private, no-store');
        } catch (ThreadFreshnessAccessDeniedException) {
            return Response::text('Permission denied.',403)->withHeader('Cache-Control','no-store');
        } catch (ThreadFreshnessException|InvalidArgumentException) {
            return Response::text('Thread freshness is unavailable.',404)->withHeader('Cache-Control','no-store');
        }
    }
}
